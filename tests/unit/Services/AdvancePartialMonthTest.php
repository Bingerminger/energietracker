<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ScenarioService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket K2a) — Abschlag im angeschnittenen Monat: eine Rechnung für Saldo-Karte, Abrechnungsjahr, „Rechnung
 * prüfen“, Kachel und Werkstatt.
 *
 * Deckt ein einzelner Vertrag einen Monat nur zum Teil (Einzug am 10., Ende am 14. ohne Folgevertrag), trägt die
 * Monatszeile den Abschlag schon für seine Tage (62 € × 21/30 = 43,40 € für 10.–30.04.). Abrechnungsjahr, „Rechnung
 * prüfen“ und die Kachel kürzten ihn bis v3.12 noch einmal nach den Tagen des Kalendermonats (43,40 € × 21/30 = 30,38 €);
 * die Karte rechnet ihn aus dem Plan und lag richtig. Jetzt nennt die Zeile die Vertragstage (`contract_from`,
 * `contract_until`) und jeder Teilzeitraum teilt nach ihnen, auch Grundpreis und Rest des Monats in der Werkstatt.
 *
 * Erfundener Einzug, von Hand: Strom 10 kWh je Tag seit 10.04.2026, Stände am 10.04. und an jedem Monatsersten bis
 * 01.09.; Vertrag ab 10.04.: 25 ct/kWh, Grundpreis 9 €/Monat, Abschlag 62 €/Monat; Stichtag 01.01.; heute 15.09.2026.
 *   Menge       144 Tage × 10 kWh × 0,25 €        = 360,00 €
 *   Grundpreis  9 € × 21/30 + 4 × 9 €            =  42,30 €
 *   Abschläge   62 € × 21/30 + 4 × 62 €          = 291,40 €   (bis v3.12 im Abrechnungsjahr 278,38 €)
 *   Saldo       360,00 + 42,30 − 291,40          = 110,90 €
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(BillService::class)]
#[CoversClass(ScenarioService::class)]
final class AdvancePartialMonthTest extends ServiceTestCase
{
    private const TODAY = '2026-09-15';

    /** Zähler mit einem Gerät ab `$installed` und Ständen an `$dates` (`$perDay` je Tag seit `$installed`). */
    private function meterWithReadings(string $utility, string $installed, array $dates, float $perDay): string
    {
        $dev = "d_$utility";
        $id = $this->setMeterDevices($utility, [['id' => $dev, 'serial' => null, 'installed_on' => $installed, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        foreach ($dates as $d) {
            $days = (int)(new \DateTimeImmutable($installed))->diff(new \DateTimeImmutable($d))->days;
            $rows[] = ['date' => $d, 'counter' => round($perDay * $days, 3), 'device_id' => $dev];
        }
        $this->setReadings($utility, $id, $rows);
        return $id;
    }

    /** @return list<string> der Tag `$first`, dann jeder Monatserste bis `$last`, dazu `$tail` */
    private static function dates(string $first, string $last, array $tail = []): array
    {
        $out = [$first];
        for ($d = (new \DateTimeImmutable($first))->modify('first day of next month'); $d->format('Y-m-d') <= $last; $d = $d->modify('first day of next month')) {
            $out[] = $d->format('Y-m-d');
        }
        return array_values(array_unique(array_merge($out, $tail)));
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function periods(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills());
    }

    /** @return array<string,array<string,mixed>> Monatszeilen nach ym */
    private function rows(string $utility, string $id, string $today = self::TODAY): array
    {
        $m = $this->meters->get($utility, $id) ?? $this->meters->groupTarget($utility, $id);
        return $this->consumption->atDate($today, fn() => array_column($this->consumption->forMeter($utility, $m), null, 'ym'));
    }

    private function card(string $utility, array $target, string $today = self::TODAY): array
    {
        return $this->consumption->contractStatus($utility, $target, $today)['contracts'][0];
    }

    /** Periode, die am Tag `$from` beginnt */
    private function period(string $utility, array $target, string $from, string $today = self::TODAY): array
    {
        $all = $this->periods()->forTarget($utility, $target, true, $today)['periods'];
        $hit = array_values(array_filter($all, fn($p) => $p['from'] === $from));
        self::assertNotEmpty($hit, "keine Periode ab $from: " . implode(', ', array_column($all, 'from')));
        return $hit[0];
    }

    private function moveIn(string $utility = 'strom', array $contract = []): string
    {
        $id = $this->meterWithReadings($utility, '2026-04-10', self::dates('2026-04-10', '2026-09-01'), $utility === 'wasser' ? 0.3 : 10.0);
        $base = ['meter_id' => $id, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-04-10',
                 'advance_payments' => [['from' => '2026-04-10', 'amount_eur' => 62]]];
        $base += $utility === 'wasser'
            ? ['trinkwasser' => ['working_prices' => [['from' => '2026-04-10', 'ct_per_m3' => 200.0]], 'base_prices' => [['from' => '2026-04-10', 'eur_per_month' => 9.0]]],
               'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2026-04-10', 'ct_per_m3' => 250.0]]]]
            : ['working_prices' => [['from' => '2026-04-10', 'ct_per_kwh' => 25.0]], 'base_prices' => [['from' => '2026-04-10', 'eur_per_month' => 9.0]]];
        $this->contracts->create($utility, $contract + $base);
        return $id;
    }

    public function testTheMoveInMonthCountsItsAdvanceOnceInEveryView(): void
    {
        $id = $this->moveIn();
        $m = $this->meters->get('strom', $id);
        $april = $this->rows('strom', $id)['2026-04'];
        self::assertSame(43.40, $april['advance_eur'], '62 € × 21/30');
        self::assertSame(['2026-04-10', '2026-04-30'], [$april['contract_from'], $april['contract_until']], 'die Zeile nennt die Vertragstage');
        self::assertArrayNotHasKey('contract_from', $this->rows('strom', $id)['2026-05'], 'ganzer Monat: kein neues Feld');

        $card = $this->card('strom', $m)['balance_measured'];
        $year = $this->period('strom', $m, '2026-04-10');
        self::assertSame('2026-08-31', $year['as_of']);
        self::assertEqualsWithDelta(291.40, $card['advance'], 0.005, 'Karte');
        self::assertEqualsWithDelta(291.40, $year['advances'], 0.005, 'Abrechnungsjahr (bis v3.12 278,38 €)');
        self::assertEqualsWithDelta(43.40, $year['months'][0]['advance_eur'], 0.005, 'erster Monat der Periode (bis v3.12 30,38 €)');
        self::assertEqualsWithDelta(110.90, $card['balance'], 0.005);
        self::assertEqualsWithDelta(110.90, $year['balance'], 0.005, 'Abrechnungsjahr = Karte');
        // „Rechnung prüfen“ für denselben Zeitraum
        $bill = $this->bills()->create('strom', ['meter_id' => $id, 'period_from' => '2026-04-10', 'period_to' => '2026-08-31', 'issued_on' => '2026-09-10',
            'invoice' => ['energy_kwh' => 1440.0, 'amount_eur' => 402.30, 'advances_paid_eur' => 291.40]]);
        self::assertEqualsWithDelta(291.40, $this->bills()->compare('strom', $bill['id'])['ours']['advances'], 0.005, 'Rechnung prüfen');
        // Kachel: Abschlag und Kosten der gemessenen Tage, summiert über die Monatszeilen
        $rows = $this->rows('strom', $id);
        self::assertEqualsWithDelta(43.40, $rows['2026-04']['advance_measured_eur'], 0.005, 'Kachel April (bis v3.12 30,38 €)');
        self::assertEqualsWithDelta(58.80, $rows['2026-04']['cost_measured'], 0.005, 'Kachel April: 210 kWh × 0,25 + 9 × 21/30 (bis v3.12 56,91 €)');
        $tile = array_sum(array_map(fn($r) => (float)$r['cost_measured'] - (float)$r['advance_measured_eur'], $rows));
        self::assertEqualsWithDelta($card['balance'], $tile, 0.005, 'Kachel = Karte');
    }

    /** Dieselbe Regel für Wasser und Gas (Abschlag unabhängig von der Menge: 62 € × 21/30 + 4 × 62 €). */
    public function testWaterAndGas(): void
    {
        foreach (['wasser', 'gas'] as $u) {
            $id = $this->moveIn($u);
            $m = $this->meters->get($u, $id);
            $card = $this->card($u, $m)['balance_measured'];
            $year = $this->period($u, $m, '2026-04-10');
            self::assertEqualsWithDelta(291.40, $year['advances'], 0.005, "$u: Abrechnungsjahr");
            self::assertEqualsWithDelta($card['advance'], $year['advances'], 0.005, "$u: Karte = Abrechnungsjahr");
            self::assertEqualsWithDelta($card['balance'], $year['balance'], 0.005, "$u: Saldo Karte = Abrechnungsjahr");
        }
    }

    /**
     * Beginn und Ende im selben Jahr, Schlussablesung am Tag nach dem Ende (20.10.): April 43,40 €, Mai bis September
     * 5 × 62 €, Oktober 62 € × 19/31 = 38,00 € → 391,40 € (bis v3.12 im Abrechnungsjahr 363,67 €).
     */
    public function testBeginAndEndInTheSameYear(): void
    {
        $id = $this->meterWithReadings('strom', '2026-04-10', self::dates('2026-04-10', '2026-10-01', ['2026-10-20']), 10.0);
        $this->contracts->create('strom', ['meter_id' => $id, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-04-10',
            'end' => '2026-10-19', 'auto_renews' => false, 'working_prices' => [['from' => '2026-04-10', 'ct_per_kwh' => 25.0]],
            'advance_payments' => [['from' => '2026-04-10', 'amount_eur' => 62]]]);
        $m = $this->meters->get('strom', $id);
        $oct = $this->rows('strom', $id, '2026-11-05')['2026-10'];
        self::assertSame([38.0, '2026-10-01', '2026-10-19'], [$oct['advance_eur'], $oct['contract_from'], $oct['contract_until']]);
        $year = $this->period('strom', $m, '2026-04-10', '2026-11-05');
        self::assertSame('2026-10-19', $year['as_of']);
        self::assertEqualsWithDelta(391.40, $year['advances'], 0.005);
        self::assertEqualsWithDelta($this->card('strom', $m, '2026-11-05')['balance_measured']['advance'], $year['advances'], 0.005, 'Karte = Abrechnungsjahr');
    }

    /**
     * Das Beispiel der Doku (Vertrag bis 14.07. ohne Folgevertrag, 90 € tagesanteilig, Schlussablesung 15.07.): Juli
     * 90 € × 14/31 = 40,65 €, zusammen 580,65 € in Karte, Abrechnungsjahr und „Rechnung prüfen“ der Schlussrechnung
     * (bis v3.12 dort 558,36 €: 40,65 € noch einmal × 14/31).
     */
    public function testTheEndWithoutSuccessorFromTheDocs(): void
    {
        $id = $this->meterWithReadings('strom', '2025-12-01', self::dates('2026-01-01', '2026-07-01', ['2026-07-15']), 10.0);
        $this->contracts->create('strom', ['meter_id' => $id, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'end' => '2026-07-14', 'auto_renews' => false, 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 90]]]);
        $m = $this->meters->get('strom', $id);
        $today = '2026-08-01';
        self::assertEqualsWithDelta(580.65, $this->card('strom', $m, $today)['balance_measured']['advance'], 0.005, 'Karte');
        $year = $this->period('strom', $m, '2026-01-01', $today);
        self::assertEqualsWithDelta(580.65, $year['advances'], 0.005, 'Abrechnungsjahr (bis v3.12 558,36 €)');
        $bill = $this->bills()->create('strom', ['meter_id' => $id, 'period_from' => '2026-01-01', 'period_to' => '2026-07-14', 'issued_on' => '2026-07-31',
            'invoice' => ['energy_kwh' => 1950.0, 'amount_eur' => 585.0, 'advances_paid_eur' => 580.65]]);
        self::assertEqualsWithDelta(580.65, $this->bills()->compare('strom', $bill['id'])['ours']['advances'], 0.005, 'Rechnung prüfen');
        self::assertEqualsWithDelta(580.65, $this->period('strom', $m, '2026-01-01', $today)['advances'], 0.005, 'Periode der Schlussrechnung');
    }

    /**
     * Periodengrenze und Vertragsbeginn im selben Monat (Vertrag ab 10.04., Stichtag 20.04.): Jede Periode bekommt ihren
     * Teil der 21 Vertragstage, zusammen wieder 43,40 €. Bis 19.04.: 62 € × 10/30 = 20,67 €; ab 20.04.: 62 € × 11/30 =
     * 22,73 € (bis v3.12 14,47 € und 15,91 €).
     */
    public function testAPeriodBoundaryInsideTheContractMonth(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '04-20']);
        $id = $this->moveIn();
        $m = $this->meters->get('strom', $id);
        $all = $this->periods()->forTarget('strom', $m, true, self::TODAY)['periods'];
        $april = array_sum(array_map(fn($p) => (float)($p['months'][0]['advance_eur'] ?? 0), array_filter($all, fn($p) => $p['from'] <= '2026-04-20')));
        self::assertEqualsWithDelta(43.40, $april, 0.005, 'April zusammen');
        $running = array_values(array_filter($all, fn($p) => !empty($p['running'])))[0];
        self::assertSame('2026-04-20', $running['from']);
        self::assertEqualsWithDelta(22.73 + 248.0, $running['advances'], 0.005);
        self::assertEqualsWithDelta($this->card('strom', $m)['balance_measured']['advance'], $running['advances'], 0.005, 'Karte = Abrechnungsjahr');
    }

    /**
     * Vertragszeile ab dem Tag nach einer Rechnung im Einzugsmonat (Rechnung 10.04. bis 19.04., Saldo ab 20.04.): Der
     * Grundpreis des Aprils zählt 11 der 21 Vertragstage, 9 € × 21/30 × 11/21 = 3,30 € (bis v3.12 × 11/30: 2,31 €), wie die
     * Karte: zusammen mit Mai bis August 39,30 €.
     */
    public function testTheContractRowFromABillInsideTheMoveInMonth(): void
    {
        $id = $this->moveIn();
        $this->bills()->create('strom', ['meter_id' => $id, 'period_from' => '2026-04-10', 'period_to' => '2026-04-19', 'issued_on' => '2026-04-25',
            'invoice' => ['energy_kwh' => 90.0, 'amount_eur' => 25.50, 'advances_paid_eur' => 20.67]]);
        $c = $this->card('strom', $this->meters->get('strom', $id));
        self::assertSame('2026-04-20', $c['balance_measured']['from']);
        self::assertEqualsWithDelta(39.30, $c['balance_measured']['base'], 0.005, 'Karte');
        self::assertEqualsWithDelta(39.30, $c['actual_base_total'], 0.005, 'Vertragszeile (bis v3.12 38,31 €)');
    }

    /** Ganze Rate am Fälligkeitstag und Stände schon vor dem Vertrag: unverändert (die Raten zählen nach Termin). */
    public function testWholeRatesAndReadingsBeforeTheContractStayAsTheyWere(): void
    {
        $this->settings->set(['advance_counting' => 'due_day', 'advance_due_day' => 15]);
        $id = $this->moveIn();
        $m = $this->meters->get('strom', $id);
        // 15.04. bis 15.08.: fünf Raten
        self::assertEqualsWithDelta(310.0, $this->period('strom', $m, '2026-04-10')['advances'], 0.005);
        self::assertEqualsWithDelta(310.0, $this->card('strom', $m)['balance_measured']['advance'], 0.005);

        $this->settings->set(['advance_counting' => 'pro_rata']);
        $id = $this->meterWithReadings('strom', '2025-12-01', self::dates('2026-01-01', '2026-09-01'), 10.0);
        $this->store->write('strom/contracts.json', []);
        $this->contracts->create('strom', ['meter_id' => $id, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-04-10',
            'working_prices' => [['from' => '2026-04-10', 'ct_per_kwh' => 25.0]], 'advance_payments' => [['from' => '2026-04-10', 'amount_eur' => 62]]]);
        $m = $this->meters->get('strom', $id);
        // die Periode beginnt mit den Daten am 01.01.: der April liegt ganz in ihr
        self::assertEqualsWithDelta(291.40, $this->period('strom', $m, '2026-01-01')['advances'], 0.005);
    }

    /** Gruppenvertrag (HT/NT), Ende am 14.07. ohne Folgevertrag: 6 × 60 € + 60 € × 14/31 = 387,10 € (bis v3.12 372,24 €). */
    public function testAGroupContractEndingMidMonth(): void
    {
        $dev = fn(string $id) => [['id' => "d_$id", 'serial' => null, 'installed_on' => '2025-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $meter = fn(string $id, string $obis) => ['id' => $id, 'name' => $id, 'icon' => '', 'created_at' => '2025-12-01', 'active' => true,
            'notes' => '', 'obis' => $obis, 'meter_group_id' => 'g_dt', 'devices' => $dev($id)];
        $this->store->write('strom/meters.json', [$meter('m_ht', '1.8.1'), $meter('m_nt', '1.8.2')]);
        $this->store->write('strom/meter_groups.json', [['id' => 'g_dt', 'name' => 'Doppeltarif', 'created_at' => '2025-12-01']]);
        $rows = [];
        foreach (['m_ht', 'm_nt'] as $mid) {
            foreach (self::dates('2026-01-01', '2026-07-01', ['2026-07-15']) as $i => $d) {
                $days = (int)(new \DateTimeImmutable('2026-01-01'))->diff(new \DateTimeImmutable($d))->days;
                $rows[] = ['id' => "r_{$mid}_$i", 'meter_id' => $mid, 'date' => $d, 'counter' => 4.0 * $days, 'device_id' => "d_$mid",
                    'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $rows);
        $this->store->write('strom/contracts.json', [[
            'id' => 'c_dt', 'meter_id' => null, 'meter_group_id' => 'g_dt', 'provider' => 'Musterwerk', 'tariff_name' => 'Duo',
            'start' => '2026-01-01', 'end' => '2026-07-14', 'auto_renews' => false, 'notes' => '',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'working_prices_by_meter' => ['m_ht' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'm_nt' => [['from' => '2026-01-01', 'ct_per_kwh' => 20.0]]],
            'base_prices' => [], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 60.0]],
            'bonuses' => [], 'special_payments' => [], 'is_shadow' => false,
        ]]);
        $group = $this->meters->groupTarget('strom', 'g_dt');
        $today = '2026-08-01';
        $year = $this->period('strom', $group, '2026-01-01', $today);
        self::assertEqualsWithDelta(387.10, $year['advances'], 0.005, 'Abrechnungsjahr der Gruppe');
        self::assertEqualsWithDelta($this->card('strom', $group, $today)['balance_measured']['advance'], $year['advances'], 0.005, 'Karte = Abrechnungsjahr');
        self::assertSame(['2026-07-01', '2026-07-14'], [$this->rows('strom', 'g_dt', $today)['2026-07']['contract_from'], $this->rows('strom', 'g_dt', $today)['2026-07']['contract_until']]);
    }

    /**
     * Werkstatt: Rest des Monats nach dem Stand 10.07. bei einem Vertrag bis 14.07. (Zeile 90 € × 14/31 = 40,65 €,
     * Grundpreis 9 € × 14/31 = 4,06 €). Vom 11. bis 14.07. zählen 4 der 14 Vertragstage: Abschlag 11,61 €, Grundpreis
     * 1,16 €, Arbeitspreis 4 Tage × 3,00 € (bis v3.12 21/31 des schon gekürzten Betrags, 27,54 € und 2,75 €, und
     * 21 Tage Arbeitspreis, auch nach dem Vertragsende).
     */
    public function testTheWorkshopRestAfterTheContractEnd(): void
    {
        $running = ['as_of' => '2026-07-10', 'until' => '2026-12-31'];
        $row = ['ym' => '2026-07', 'days' => 10, 'kwh_cost' => 30.0, 'base_price_eur' => 4.06, 'advance_eur' => 40.65,
                'contract_from' => '2026-07-01', 'contract_until' => '2026-07-14'];
        $rest = ScenarioService::restOfMonth($running, [['ym' => '2026-08', 'days' => 31]], [$row]);
        self::assertSame(11.61, $rest['advance']);
        self::assertSame(round(30.0 / 10 * 4 + 1.16, 2), $rest['cost'], 'Arbeitspreis und Grundpreis nur für die Vertragstage');
    }
}
