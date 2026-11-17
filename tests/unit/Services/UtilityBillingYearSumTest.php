<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\ReportModelService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 — Das Abrechnungsjahr der ganzen Verbrauchsart (Übersicht, Anhang des Jahresberichts, CSV, KI ohne Zähler) ist
 * die Summe seiner Ziele: eine Zählergruppe mit Gruppenvertrag als ein Ziel, jeder andere Zähler für sich. Jedes Ziel
 * endet nach seiner eigenen Regel wie in seinem Abrechnungsjahr:
 *  - eine Gruppe am frühesten letzten Stand ihrer Mitglieder (ConsumptionService::measuredUntil),
 *  - ein Zähler an seiner letzten echten Ablesung,
 *  - der letzte Vertrag an seinem letzten Tag, auch wenn die Übergabe Tage später abgelesen ist,
 *  - ein Zähler ohne Ablesung (der beim Erststart angelegte Hauptzähler) zählt nichts und schaltet keine Regel ab.
 * Bis dahin rechnete die Summe der Art alle Zähler bis zum spätesten Stand: Grundpreis und Abschläge einer Gruppe liefen
 * bis dorthin, die Menge des früher abgelesenen Zählwerks nicht; und neben einem leeren Hauptzähler zählte die Menge
 * nach dem Vertragsende bis zur Übergabe mit.
 *
 * Alle Werte erfunden, von Hand gerechnet; Tageskonvention: Eine Ablesung am Tag D zählt bis D − 1.
 */
#[CoversClass(BillingPeriodService::class)]
final class UtilityBillingYearSumTest extends McpHouseholdTestCase
{
    private const STAND = '2026-11-02';

    /** Monatsstände am Ersten von `$from` bis `$to` (JJJJ-MM-TT), dazu `$last`; Stand = Tage seit `$from` × Tagesmenge. */
    private static function daily(string $from, string $to, ?string $last, float $perDay): array
    {
        $rows = [];
        for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 month')) $rows[] = $d->format('Y-m-d');
        if ($last !== null) $rows[] = $last;
        return array_map(fn(string $d) => [$d, $perDay * (int)(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($d))->days], $rows);
    }

    private function bp(): BillingPeriodService
    {
        return $this->svc(BillingPeriodService::class);
    }

    /** Die Periode, die am `$from` beginnt, der Art oder eines Ziels. */
    private function period(?array $target, string $from = '2026-01-01', string $today = self::STAND): array
    {
        $all = $target === null ? $this->bp()->forUtility('strom', true, $today) : $this->bp()->forTarget('strom', $target, true, $today);
        foreach ($all['periods'] as $p) if ($p['from'] === $from) return $p;
        self::fail("keine Periode ab $from");
    }

    /**
     * HT 8 kWh je Tag bis 13.10., NT 3 kWh je Tag nur bis 27.07.; Gruppenvertrag ab 01.01.2026: HT 32 ct, NT 21 ct,
     * Grundpreis 9 €/Monat, Abschlag 75 €/Monat. Daneben der leere Hauptzähler.
     */
    private function peakOffPeak(): string
    {
        $gid = (string)$this->meters->createGroup('strom', ['name' => 'Doppeltarif'])['id'];
        $this->meters('strom', ['m_strom_default' => 'Hauptzähler', 'm_ht' => 'HT', 'm_nt' => 'NT'],
            ['m_ht' => ['meter_group_id' => $gid], 'm_nt' => ['meter_group_id' => $gid]]);
        $this->counters('strom', ['m_ht' => self::daily('2026-01-01', '2026-10-01', '2026-10-13', 8.0),
                                  'm_nt' => self::daily('2026-01-01', '2026-07-01', '2026-07-27', 3.0)]);
        $this->contracts->create('strom', ['meter_group_id' => $gid, 'provider' => 'Stromwerk Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 32.0]],
            'working_prices_by_meter' => ['m_ht' => [['from' => '2026-01-01', 'ct_per_kwh' => 32.0]], 'm_nt' => [['from' => '2026-01-01', 'ct_per_kwh' => 21.0]]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 9.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 75.0]]]);
        return $gid;
    }

    /**
     * Gruppe bis zum frühesten Stand 27.07. (gerechnet bis 26.07., 207 Tage):
     *   HT 207 × 8 = 1.656 kWh × 0,32 € = 529,92 €;  NT 207 × 3 = 621 kWh × 0,21 € = 130,41 €
     *   Grundpreis 6 × 9 € + 9 € × 26/31 = 61,55 €;  Abschläge 6 × 75 € + 75 € × 26/31 = 512,90 €
     *   Saldo 660,33 + 61,55 − 512,90 = 208,98 €
     * Die Summe der Art nennt dasselbe (bis dahin Grundpreis und Abschläge bis 12.10., die Menge von NT nur bis 26.07.).
     */
    public function testAGroupReadAtDifferentDaysEndsTheUtilityLikeTheGroup(): void
    {
        $group = $this->meters->groupTarget('strom', $this->peakOffPeak());
        $g = $this->period($group);
        self::assertSame(['2026-07-26', 207], [$g['as_of'], $g['days']]);
        self::assertSame([2277.0, 512.9, 208.98], [$g['kwh'], $g['advances'], $g['balance']], 'Gruppe von Hand');
        $art = $this->period(null);
        self::assertSame('2026-07-26', $art['as_of'], 'die Art endet mit ihrem einzigen Ziel');
        self::assertSame([2277.0, 61.55, 512.9, 208.98], [$art['measured_kwh'], $art['fixed_cost'], $art['advances'], $art['balance']],
            'Summe der Art = Gruppe');
    }

    /**
     * Dazu ein eigener Zähler mit eigenem Vertrag, 5 kWh je Tag bis 13.10. (bis 12.10., 285 Tage): 1.425 kWh × 0,30 € = 427,50 €,
     * Grundpreis 9 × 10 € + 10 € × 12/31 = 93,87 €, Abschläge 9 × 50 € + 50 € × 12/31 = 469,35 €, Saldo 52,02 €.
     * Summe der Art: Kosten 721,88 + 521,37 = 1.243,25 €, Abschläge 512,903 + 469,355 = 982,26 € → 260,99 € (je Ziel gerundet
     * 208,98 + 52,02), 3.702 kWh, gerechnet bis 12.10. (das späteste Ziel).
     */
    public function testTheUtilityIsTheSumOfItsTargets(): void
    {
        $gid = $this->peakOffPeak();
        $all = $this->store->read('strom/meters.json', []);
        $all[] = ['id' => 'm_wp', 'name' => 'Wärmepumpe', 'icon' => '', 'created_at' => '2020-01-01', 'active' => true, 'notes' => '',
            'devices' => [['id' => 'd_m_wp', 'serial' => null, 'installed_on' => '2020-01-01', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]];
        $this->store->write('strom/meters.json', $all);
        $rows = $this->store->read('strom/readings.json', []);
        foreach (self::daily('2026-01-01', '2026-10-01', '2026-10-13', 5.0) as $i => [$d, $c]) {
            $rows[] = ['id' => "r_wp_$i", 'meter_id' => 'm_wp', 'date' => $d, 'counter' => $c, 'device_id' => 'd_m_wp', 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write('strom/readings.json', $rows);
        $this->contracts->create('strom', ['meter_id' => 'm_wp', 'provider' => 'Wärmestrom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 50.0]]]);
        $wp = $this->period($this->meters->get('strom', 'm_wp'));
        self::assertSame(['2026-10-12', 1425.0, 52.02], [$wp['as_of'], $wp['kwh'], $wp['balance']], 'Zähler von Hand');
        $g = $this->period($this->meters->groupTarget('strom', $gid));
        $art = $this->period(null);
        self::assertSame(['2026-10-12', 285], [$art['as_of'], $art['days']], 'bis zum spätesten Ziel');
        self::assertSame(3702.0, $art['measured_kwh']);
        self::assertSame(260.99, $art['balance'], 'von Hand');
        // Ansicht gegen Ansicht: Art = Summe der Ziele
        foreach (['energy_cost', 'fixed_cost', 'cost', 'advances', 'balance'] as $k) {
            self::assertEqualsWithDelta($g[$k] + $wp[$k], $art[$k], 0.011, "Art = Gruppe + Zähler ($k)");
        }
        self::assertEqualsWithDelta($g['kwh'] + $wp['kwh'], $art['kwh'], 0.05);
        // Monate der Art: Oktober nur noch der eigene Zähler (Gruppe endet am 26.07.)
        $oct = array_values(array_filter($art['months'], fn($m) => $m['ym'] === '2026-10'))[0];
        self::assertEqualsWithDelta(5.0 * 12, $oct['kwh'], 0.05, 'Oktober: nur der Zähler, der dann gemessen ist');
        self::assertEqualsWithDelta(array_sum(array_column($art['months'], 'advance_eur')), $art['advances'], 0.011, 'Monate = Periode');
    }

    /**
     * Jahreswechsel, von Hand: NT zuletzt am 31.12.2025 abgelesen (letzter Tag der Periode: zählt den Tag mit), HT weiter bis
     * 01.03.2026; Gruppenvertrag ab 01.01.2025. 2025 rechnet die Gruppe ganz (HT 365 × 8 = 2.920 kWh, NT bis zum Stand 31.12.
     * 364 × 3 = 1.092 kWh), 2026 gar nicht (frühester Stand vor der Periode). Die Art sagt dasselbe.
     */
    public function testAtTheTurnOfTheYear(): void
    {
        $gid = (string)$this->meters->createGroup('strom', ['name' => 'Doppeltarif'])['id'];
        $this->meters('strom', ['m_strom_default' => 'Hauptzähler', 'm_ht' => 'HT', 'm_nt' => 'NT'],
            ['m_ht' => ['meter_group_id' => $gid], 'm_nt' => ['meter_group_id' => $gid]]);
        $this->counters('strom', ['m_ht' => self::daily('2025-01-01', '2026-03-01', null, 8.0),
                                  'm_nt' => self::daily('2025-01-01', '2025-12-01', '2025-12-31', 3.0)]);
        $this->contracts->create('strom', ['meter_group_id' => $gid, 'provider' => 'Stromwerk Musterstadt', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 9.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 75.0]]]);
        $group = $this->meters->groupTarget('strom', $gid);
        $g25 = $this->period($group, '2025-01-01', '2026-03-10');
        $a25 = $this->period(null, '2025-01-01', '2026-03-10');
        self::assertSame(['2025-12-31', 4012.0], [$g25['as_of'], $g25['kwh']]);
        self::assertSame([$g25['as_of'], $g25['kwh'], $g25['balance']], [$a25['as_of'], $a25['measured_kwh'], $a25['balance']], '2025: Art = Gruppe');
        $a26 = $this->period(null, '2026-01-01', '2026-03-10');
        self::assertSame([0.0, 0.0, 0.0], [$a26['measured_kwh'], $a26['advances'], $a26['balance']], '2026: die Gruppe rechnet nicht mehr');
    }

    /**
     * Vertrag 01.01. bis 17.08.2026 ohne Folgevertrag (28 ct, 8 €/Monat, 60 €/Monat), 7 kWh je Tag, Übergabe am 23.08.
     * Bis zum letzten Vertragstag 229 Tage × 7 kWh = 1.603 kWh (bis zur Übergabe wären es 234 Tage, 1.638 kWh).
     *
     * @return array<string,mixed> der Zähler
     */
    private function endedContract(array $more = []): array
    {
        $this->meters('strom', ['m_whg' => 'Wohnung'] + $more);
        $this->counters('strom', ['m_whg' => self::daily('2026-01-01', '2026-08-01', '2026-08-23', 7.0)]
            + (isset($more['m_garage']) ? ['m_garage' => self::daily('2026-01-01', '2026-10-01', null, 1.0)] : []));
        $this->contracts->create('strom', ['meter_id' => 'm_whg', 'provider' => 'Stromwerk Musterstadt', 'start' => '2026-01-01', 'end' => '2026-08-17',
            'auto_renews' => false, 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 28.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 8.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 60.0]]]);
        return $this->meters->get('strom', 'm_whg');
    }

    public function testAnEmptyMainMeterDoesNotSwitchTheContractEndOff(): void
    {
        $m = $this->endedContract(['m_strom_default' => 'Hauptzähler']);
        $own = $this->period($m);
        self::assertSame(['2026-08-17', 229, 1603.0], [$own['as_of'], $own['days'], $own['kwh']], 'Zähler bis zum letzten Vertragstag');
        $art = $this->period(null);
        self::assertSame(['2026-08-17', 229], [$art['as_of'], $art['days']], 'Art: bis zum letzten Vertragstag, nicht bis zur Übergabe');
        self::assertSame(1603.0, $art['measured_kwh'], 'bis dahin 1.638 kWh');
        self::assertSame($own['balance'], $art['balance']);
        self::assertSame('2026-08-17', $art['contract_end'] ?? null, 'Ende des letzten Vertrags auch an der Art (der leere Hauptzähler rechnet nicht weiter)');
        // KI ohne Zähler liest dieselbe Periode
        $ki = self::row($this->tool('get_consumption', ['utility' => 'strom', 'granularity' => 'billing_period'], self::STAND)['rows'], '2026-01-01/2026-12-31');
        self::assertSame('2026-08-17', $ki['as_of'], 'KI');
        self::assertEqualsWithDelta(1603.0, (float)$ki['value'], 0.05, 'KI');
    }

    /**
     * Die Karte des beendeten Vertrags („8 Monate · … kWh“) nennt dieselbe Menge wie sein Abrechnungsjahr: 1.603 kWh bis zum
     * letzten Vertragstag, nicht 1.638 kWh bis zur Übergabe; das Geld war schon gleich. Variante: Übergabe am Tag nach dem Ende
     * (18.08.): ebenso 229 Tage × 7 = 1.603 kWh in beiden.
     */
    public function testTheCardOfTheEndedContractCountsTheSameQuantity(): void
    {
        $m = $this->endedContract();
        $card = $this->consumption->contractStatus('strom', $m, self::STAND)['contracts'][0];
        $year = $this->period($m);
        self::assertSame(1603.0, $year['kwh']);
        self::assertSame(1603.0, $card['actual_kwh'], 'Karte = Abrechnungsjahr');
        self::assertSame(8, $card['months_actual']);
        self::assertSame($year['balance'], $card['balance_measured']['balance'], 'das Geld bleibt');
    }

    public function testTheCardWithAHandoverOnTheDayAfterTheEnd(): void
    {
        $this->meters('strom', ['m_whg' => 'Wohnung']);
        $this->counters('strom', ['m_whg' => self::daily('2026-01-01', '2026-08-01', '2026-08-18', 7.0)]);
        $this->contracts->create('strom', ['meter_id' => 'm_whg', 'provider' => 'Stromwerk Musterstadt', 'start' => '2026-01-01', 'end' => '2026-08-17',
            'auto_renews' => false, 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 28.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 8.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 60.0]]]);
        $m = $this->meters->get('strom', 'm_whg');
        $card = $this->consumption->contractStatus('strom', $m, self::STAND)['contracts'][0];
        self::assertSame([1603.0, 1603.0], [$this->period($m)['kwh'], $card['actual_kwh']], 'bis zum Stand am 18.08.: 229 Tage');
    }

    public function testWithoutTheMainMeterTheSame(): void
    {
        $this->endedContract();
        self::assertSame(1603.0, $this->period(null)['measured_kwh']);
    }

    /**
     * Derselbe Versorger, ein neuer Vertrag ab 01.06. (kein Lieferantenwechsel, das Jahr teilt sich nicht): Der erste endet am
     * 31.05., der zweite läuft weiter. Zähler und Art rechnen bis zur letzten Ablesung (Stand 14.09., gerechnet bis 13.09.):
     * 256 Tage × 5 kWh = 1.280 kWh; Arbeit 151 Tage × 5 kWh × 0,28 € = 211,40 € und 105 Tage × 5 kWh × 0,31 € = 162,75 €.
     * Nicht bis zum Ende des ersten Vertrags (151 Tage), nur weil einer der beiden endet.
     */
    public function testAFollowUpContractOfTheSameSupplierKeepsTheYearRunning(): void
    {
        $this->meters('strom', ['m_whg' => 'Wohnung']);
        $this->counters('strom', ['m_whg' => self::daily('2026-01-01', '2026-09-01', '2026-09-14', 5.0)]);
        $base = ['meter_id' => 'm_whg', 'provider' => 'Stromwerk Musterstadt'];
        $this->contracts->create('strom', $base + ['start' => '2026-01-01', 'end' => '2026-05-31', 'auto_renews' => false,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 28.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 8.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 45.0]]]);
        $this->contracts->create('strom', $base + ['start' => '2026-06-01',
            'working_prices' => [['from' => '2026-06-01', 'ct_per_kwh' => 31.0]],
            'base_prices' => [['from' => '2026-06-01', 'eur_per_month' => 9.0]], 'advance_payments' => [['from' => '2026-06-01', 'amount_eur' => 50.0]]]);
        $own = $this->period($this->meters->get('strom', 'm_whg'));
        self::assertSame(['2026-09-13', 256, 1280.0], [$own['as_of'], $own['days'], $own['kwh']], 'Zähler bis zur letzten Ablesung');
        self::assertEqualsWithDelta(374.15, $own['energy_cost'], 0.01, 'Arbeit von Hand');
        $art = $this->period(null);
        self::assertSame(['2026-09-13', 1280.0], [$art['as_of'], $art['measured_kwh']], 'Art ebenso');
        self::assertArrayNotHasKey('contract_end', array_filter($art, fn($v) => $v !== null), 'kein Vertragsende: einer läuft weiter');
    }

    /** Ein zweiter Zähler ohne Vertrag (1 kWh je Tag bis 01.10.): Er zählt nach seinen Ablesungen, der beendete Vertrag bis zu seinem Ende. */
    public function testAMeterWithoutContractCountsByItsReadingsTheOtherEndsWithItsContract(): void
    {
        $this->endedContract(['m_garage' => 'Garage']);
        $art = $this->period(null);
        self::assertSame('2026-09-30', $art['as_of']);
        self::assertSame(1603.0 + 273.0, $art['measured_kwh'], '1.603 kWh bis 17.08. und 273 kWh bis 30.09.');
    }

    /** Der Anhang „Abrechnungsjahre“ des Jahresberichts nennt die Menge der Art (Ansicht gegen Ansicht). */
    public function testTheReportAppendixShowsTheSum(): void
    {
        $this->endedContract(['m_strom_default' => 'Hauptzähler']);
        $model = $this->svc(ReportModelService::class)->build(2026, ['today' => self::STAND, 'billing' => true]);
        $rows = [];
        foreach ($model['billing'] ?? [] as $u) foreach ($u['rows'] ?? [] as $r) $rows[] = $r;
        $line = implode(' | ', array_map(fn($r) => implode(' ', array_map('strval', $r)), $rows));
        self::assertStringContainsString('1.603', $line, 'Anhang: 1.603 kWh');
        self::assertStringNotContainsString('1.638', $line);
    }
}
