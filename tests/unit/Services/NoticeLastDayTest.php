<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillService;
use Energietracker\Services\RightsService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\LetterService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Der Kündigungsstichtag ist der letzte Tag, an dem die Kündigung zugehen kann, damit die Frist nach
 * §§ 187 Abs. 1, 188 Abs. 2, 3 BGB spätestens mit dem Vertragsende abläuft (Rechtsgrundlagen Nr. 52). Bis v3.12 nahm
 * die App immer den Tag mit derselben Zahl wie das Ende: Endete der Vertrag am Monatsletzten eines kürzeren Monats,
 * lag der Stichtag 1 bis 3 Tage zu früh, und an diesen Tagen hieß die Frist schon „verpasst“.
 *
 * Von Hand (vorwärts: Zugang D, Frist ab D + 1, Ende am Tag mit derselben Zahl, im kürzeren Monat am Letzten):
 *   Ende 29.02.2028, 1 Monat: Zugang 31.01.2028 → „31.02.“ fehlt → 29.02.2028 = Ende, gewahrt; Zugang 01.02. → 01.03.
 *     Stichtag 31.01.2028 (bis v3.12: 29.01.2028)
 *   Ende 30.06.2028, 1 Monat: Zugang 31.05. → „31.06.“ fehlt → 30.06. gewahrt; Stichtag 31.05.2028 (bisher 30.05.)
 *   Ende 30.09.2029, 6 Monate: Zugang 31.03.2029 → „31.09.“ fehlt → 30.09. gewahrt; Stichtag 31.03.2029 (bisher 30.03.)
 *   Ende 31.03.2029, 1 Monat: Zugang 28.02.2029 → 28.03.; Zugang 01.03. → 01.04.; Stichtag 28.02.2029 (unverändert)
 *   Ende 28.02.2028 (kein Monatsletzter, 2028 hat den 29.02.), 1 Monat: Zugang 28.01. → 28.02. gewahrt; 29.01. → 29.02.
 *     zu spät; Stichtag 28.01.2028 (unverändert)
 *   Jahreswechsel: Ende 31.01.2028, 1 Monat: Zugang 31.12.2027 → 31.01.2028; Stichtag 31.12.2027;
 *     2 Monate: Zugang 30.11.2027 → „30.01.“ = 30.01.2028 ≤ 31.01., Zugang 01.12. → 01.02.; Stichtag 30.11.2027
 *   Frist in Tagen unverändert: Ende 31.01.2028, 14 Tage: Zugang 17.01. → Frist 18.01.–31.01.; Stichtag 17.01.2028
 * Erfundene Verträge, feste Tage (nie heute).
 */
#[CoversClass(ContractService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(AgendaService::class)]
#[CoversClass(LetterService::class)]
final class NoticeLastDayTest extends ServiceTestCase
{
    // ── unabhängige Vorwärtsrechnung (eigener Code, ohne DateTime und ohne die App) ──

    private static function isLeap(int $y): bool
    {
        return ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
    }

    private static function monthLen(int $y, int $m): int
    {
        return [31, self::isLeap($y) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$m - 1];
    }

    /** @return array{0:int,1:int,2:int} */
    private static function ymd(string $iso): array
    {
        return [(int)substr($iso, 0, 4), (int)substr($iso, 5, 2), (int)substr($iso, 8, 2)];
    }

    private static function iso(int $y, int $m, int $d): string
    {
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** § 188 Abs. 2, 3 BGB (Fall des § 187 Abs. 1): Fristende bei Zugang am Tag $iso und einer Frist von $n Monaten. */
    private static function forwardEnd(string $iso, int $n): string
    {
        [$y, $m, $d] = self::ymd($iso);
        $k = $y * 12 + ($m - 1) + $n;
        $ty = intdiv($k, 12);
        $tm = $k % 12 + 1;
        return self::iso($ty, $tm, min($d, self::monthLen($ty, $tm)));
    }

    private static function dayBefore(string $iso): string
    {
        [$y, $m, $d] = self::ymd($iso);
        if ($d > 1) return self::iso($y, $m, $d - 1);
        if ($m > 1) return self::iso($y, $m - 1, self::monthLen($y, $m - 1));
        return self::iso($y - 1, 12, 31);
    }

    private static function dayAfter(string $iso): string
    {
        [$y, $m, $d] = self::ymd($iso);
        if ($d < self::monthLen($y, $m)) return self::iso($y, $m, $d + 1);
        if ($m < 12) return self::iso($y, $m + 1, 1);
        return self::iso($y + 1, 1, 1);
    }

    /** Der späteste Zugangstag, dessen Frist spätestens am Ende abläuft: rückwärts suchen, vorwärts prüfen. */
    private static function oracle(string $end, int $n): string
    {
        $d = $end;
        while (self::forwardEnd($d, $n) > $end) $d = self::dayBefore($d);
        return $d;
    }

    public function testEveryEndIn2027And2028AgreesWithTheForwardCheck(): void
    {
        $wrong = [];
        $cases = 0;
        foreach ([2027, 2028] as $y) {
            for ($m = 1; $m <= 12; $m++) {
                for ($d = 1; $d <= self::monthLen($y, $m); $d++) {
                    $end = self::iso($y, $m, $d);
                    foreach ([1, 2, 3, 6, 9, 12] as $n) {
                        $cases++;
                        $want = self::oracle($end, $n);
                        $got = ContractService::lastNoticeDay($end, $n);
                        if ($got !== $want) $wrong[] = "$end/$n: $got statt $want";
                        // das Orakel selbst: am Stichtag gewahrt, einen Tag später zu spät
                        self::assertLessThanOrEqual($end, self::forwardEnd($want, $n));
                        self::assertGreaterThan($end, self::forwardEnd(self::dayAfter($want), $n));
                    }
                }
            }
        }
        self::assertSame(731 * 6, $cases, 'jeder Tag 2027 und 2028 (Schaltjahr) mit sechs Fristen');
        self::assertSame([], array_slice($wrong, 0, 10), count($wrong) . ' Abweichungen');
    }

    /** @return array<string, array{string, int, string}> Ende, Monate, Stichtag von Hand (Klassenkommentar) */
    public static function handCases(): array
    {
        return [
            'Ende 29.02.2028, Schaltjahr'           => ['2028-02-29', 1, '2028-01-31'],
            'Ende 28.02.2029'                       => ['2029-02-28', 1, '2029-01-31'],
            'Ende 30.06.2028'                       => ['2028-06-30', 1, '2028-05-31'],
            'Ende 30.09.2029, sechs Monate'         => ['2029-09-30', 6, '2029-03-31'],
            'Ende 31.03.2029: Februar ist kürzer'   => ['2029-03-31', 1, '2029-02-28'],
            'Ende 31.03.2028: Schalttag'            => ['2028-03-31', 1, '2028-02-29'],
            'Ende 28.02.2028 ist kein Monatsletzter' => ['2028-02-28', 1, '2028-01-28'],
            'Ende 15.05.2028, Tag mit derselben Zahl' => ['2028-05-15', 1, '2028-04-15'],
            'Jahreswechsel, ein Monat'              => ['2028-01-31', 1, '2027-12-31'],
            'Jahreswechsel, zwei Monate'            => ['2028-01-31', 2, '2027-11-30'],
            'Fernwärme, neun Monate'                => ['2029-09-30', 9, '2028-12-31'],
        ];
    }

    #[DataProvider('handCases')]
    public function testHandCases(string $end, int $months, string $expected): void
    {
        self::assertSame($expected, ContractService::lastNoticeDay($end, $months));
        // dieselbe Regel im Vertrag (switchTiming, Frist zum Laufzeitende)
        $t = $this->contracts->switchTiming(['start' => '2026-01-01', 'end' => $end, 'notice_period_months' => $months], '2026-11-13', false, 'strom', 'DE');
        self::assertSame([$expected, 'fixed_end'], [$t['cancel_by'], $t['basis']]);
    }

    public function testANoticePeriodInDaysIsEndMinusDays(): void
    {
        $t = $this->contracts->switchTiming(['start' => '2026-01-01', 'end' => '2028-01-31', 'notice_period_days' => 14], '2026-11-13', false, 'gas', 'DE');
        self::assertSame('2028-01-17', $t['cancel_by'], 'Zugang 17.01., Frist 18.01. bis 31.01.');
    }

    /** Fernwärme ohne eingetragene Frist: die neun Monate nach § 32 AVBFernwärmeV nach derselben Regel. */
    public function testDistrictHeatingNineMonthsFollowTheSameRule(): void
    {
        $t = $this->contracts->switchTiming(['start' => '2020-01-01', 'end' => '2029-09-30'], '2026-11-13', false, 'fernwaerme', 'DE');
        self::assertSame(['2028-12-31', 'fixed_end'], [$t['cancel_by'], $t['basis']], 'Zugang 31.12.2028 → „31.09.2029“ fehlt → 30.09.');
        // die Verlängerung beginnt am Tag nach dem Ende und läuft fünf Jahre: bis 30.09.2034, Kündigung bis 31.12.2033
        self::assertSame(['until' => '2034-09-30', 'cancel_by' => '2033-12-31'], $t['renewal_term']);
        // Ende 28.02.2027: die Verlängerung ab 01.03.2027 läuft bis 29.02.2032 (Schaltjahr), nicht bis 28.02.2032
        self::assertSame(['until' => '2032-02-29', 'cancel_by' => '2031-05-31'], ContractService::heatRenewalTerm('2027-02-28', '2026-11-13'));
        // Beginn am Schalttag: fünf Jahre ab 29.02.2028 enden am 28.02.2033
        self::assertSame('2033-02-28', ContractService::heatRenewalTerm('2028-02-28', '2026-11-13')['until']);
    }

    /**
     * Fernwärme nach der Verlängerung (§ 32 Abs. 1 AVBFernwärmeV): Der Vertrag lief bis 31.12.2022, wurde nicht
     * gekündigt und verlängerte sich ab 01.01.2023 um fünf Jahre bis 31.12.2027. Kündigungstag dieser Verlängerung:
     * neun Monate vor dem 31.12.2027 = 31.03.2027 (Monatsletzter). Bis v3.12 stand er im Vertragsstatus, aber nicht
     * in „Zu tun“, Kalender und Erinnerung, weil „weiterlaufend“ jeden festen Tag ausblendete.
     */
    public function testARenewedDistrictHeatingContractKeepsItsCancellationDay(): void
    {
        $this->settings->set(['active_utilities' => ['strom', 'fernwaerme'], 'country' => 'DE']);
        $meterId = $this->setMeterDevices('fernwaerme', [['id' => 'd_fw', 'serial' => null, 'installed_on' => '2013-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('fernwaerme', $meterId, [['date' => '2026-01-01', 'device_id' => 'd_fw', 'counter' => 500.0],
            ['date' => '2026-11-01', 'device_id' => 'd_fw', 'counter' => 7500.0]]);
        $meter = $this->meters->get('fernwaerme', $meterId);
        $c = $this->contracts->create('fernwaerme', ['meter_id' => $meterId, 'provider' => 'Wärme Musterstadt', 'start' => '2013-01-01', 'end' => '2022-12-31',
            'working_prices' => [['from' => '2013-01-01', 'ct_per_kwh' => 11.0]]]);
        $row = fn(string $today) => array_column($this->consumption->contractStatus('fernwaerme', $meter, $today)['contracts'], null, 'contract_id')[$c['id']];
        $recs = fn(string $today) => (new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n))->atDate($today);
        $agenda = fn(string $today) => new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs($today), $this->i18n);

        $r = $row('2026-11-04');
        self::assertSame([true, 'renewal_term', '2027-03-31', '2028-01-01', 'cancel_by', false, '2027-12-31'],
            [$r['renewed'], $r['notice_basis'], $r['cancel_by'], $r['switch_date'], $r['remind_basis'], $r['cancel_missed'], $r['cancel_end']]);
        $events = array_values(array_filter($agenda('2026-11-04')->events(365, '2026-11-04'),
            fn($e) => ($e['ref']['contract_id'] ?? null) === $c['id'] && $e['kind'] === 'cancel_by'));
        self::assertSame(['2027-03-31'], array_column($events, 'date'), 'Agenda: „Kündigen bis 31.03.2027“');

        // 75 Tage vorher: erste Erinnerungsstufe; der Text nennt das Ende der Verlängerung, nicht das alte Ende
        $r = $row('2027-01-15');
        self::assertSame([true, 1, 75], [$r['should_remind'], $r['remind_stage'], $r['days_to_cancel']]);
        $id = RecommendationService::ruleId('r6', ['fernwaerme', $meterId, (string)$c['id']]);
        $r6 = array_values(array_filter($recs('2027-01-15')->all(), fn($x) => $x['id'] === $id));
        self::assertCount(1, $r6);
        self::assertStringContainsString('31.03.2027', $r6[0]['detail']);
        self::assertStringContainsString('31.12.2027', $r6[0]['detail']);
        self::assertStringNotContainsString('31.12.2022', $r6[0]['detail']);
    }

    /** Doku (Rechtsgrundlagen, „So rechnet die App die Termine“): dieselben Tage wie die App. */
    public function testTheDocumentedExamplesHold(): void
    {
        $root = dirname(__DIR__, 3);
        $de = ['Ende 29.02.2028 mit einem Monat Frist: 31.01.2028' => ['2028-02-29', 1, '2028-01-31'],
               'Ende 30.09.2029 mit sechs Monaten: 31.03.2029' => ['2029-09-30', 6, '2029-03-31'],
               'Ende 31.03.2029 mit einem Monat: 28.02.2029' => ['2029-03-31', 1, '2029-02-28'],
               'Ende 15.05.2028, ein Monat: 15.04.2028' => ['2028-05-15', 1, '2028-04-15']];
        $en = ['end 29.02.2028 with one month’s notice: 31.01.2028' => ['2028-02-29', 1, '2028-01-31'],
               'end 30.09.2029 with six months: 31.03.2029' => ['2029-09-30', 6, '2029-03-31'],
               'end 31.03.2029 with one month: 28.02.2029' => ['2029-03-31', 1, '2029-02-28'],
               'end 15.05.2028, one month: 15.04.2028' => ['2028-05-15', 1, '2028-04-15']];
        foreach (['docs/referenz/rechtsgrundlagen.md' => $de, 'docs/en/referenz/rechtsgrundlagen.md' => $en] as $file => $claims) {
            $text = (string)preg_replace('/\s+/u', ' ', (string)file_get_contents("$root/$file"));
            $pos = strpos($text, '<!-- doc-claim: notice-last-day -->');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            $part = substr($text, $pos, 600);
            foreach ($claims as $sentence => [$end, $n, $day]) {
                self::assertStringContainsString($sentence, $part, $file);
                self::assertSame($day, ContractService::lastNoticeDay($end, $n), "$file: $sentence");
            }
        }
    }

    /**
     * Ansicht gegen Ansicht am Stichtag und am Tag danach: Vertragsstatus, Agenda und Kündigungsbrief sagen dasselbe.
     * Strom, Ende 30.06.2028, ein Monat: Stichtag 31.05.2028 (bis v3.12 schon am 31.05. „verpasst“).
     */
    public function testStatusAgendaAndLetterAgreeOnTheLastDayAndTheDayAfter(): void
    {
        $this->settings->set(['active_utilities' => ['strom'], 'country' => 'DE']);
        $meter = $this->meters->get('strom', (string)$this->meters->defaultId('strom'));
        $c = $this->contracts->create('strom', ['meter_id' => $meter['id'], 'provider' => 'Strom Musterstadt', 'start' => '2027-07-01', 'end' => '2028-06-30',
            'notice_period_months' => 1, 'working_prices' => [['from' => '2027-07-01', 'ct_per_kwh' => 30.0]]]);
        $row = fn(string $today) => array_column($this->consumption->contractStatus('strom', $meter, $today)['contracts'], null, 'contract_id')[$c['id']];
        $letters = new LetterService(new RightsService($this->settings, $this->i18n), $this->contracts, $this->meters,
            new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n));
        $letter = fn(string $today) => $letters->render(['utility' => 'strom', 'contract_id' => $c['id'], 'kind' => 'termination',
            'sender' => ['Erika Muster', 'Musterweg 1', '12345 Musterstadt'], 'place' => 'Musterstadt'], $today)['model'];

        $on = $row('2028-05-31');
        self::assertSame(['2028-05-31', 0, false, 'cancel_by', '2028-06-30'],
            [$on['cancel_by'], $on['days_to_cancel'], $on['cancel_missed'], $on['remind_basis'], $on['cancel_end']]);
        $m = $letter('2028-05-31');
        self::assertSame('2028-06-30', $m['defaults']['extra']['term_date'] ?? null, 'am Stichtag: Termin zum Vertragsende');
        self::assertNull($m['term_gap']);

        $recs = (new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n))->atDate('2028-05-31');
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n);
        $events = array_values(array_filter($agenda->events(30, '2028-05-31'), fn($e) => ($e['ref']['contract_id'] ?? null) === $c['id'] && $e['kind'] === 'cancel_by'));
        self::assertSame(['2028-05-31'], array_column($events, 'date'), 'Agenda: „Kündigen bis 31.05.2028“');

        $after = $row('2028-06-01');
        self::assertSame(['2028-05-31', -1, true], [$after['cancel_by'], $after['days_to_cancel'], $after['cancel_missed']]);
        $m = $letter('2028-06-01');
        self::assertSame(['reason' => 'missed', 'cancel_by' => '2028-05-31', 'end' => '2028-06-30'], $m['term_gap'], 'erst am Tag danach verpasst');
    }
}
