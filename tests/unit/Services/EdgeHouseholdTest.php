<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Countries;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\Co2CostService;
use Energietracker\Services\Co2SplitService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\LetterService;
use Energietracker\Services\ReportModelService;
use Energietracker\Services\RightsService;
use Energietracker\Services\SummaryService;
use Energietracker\Services\TenancyService;
use Energietracker\Support\FieldRules;
use Energietracker\Tests\Services\Mcp\McpReadTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket K3) — Ein Testhaushalt mit Verträgen, die nicht glatt laufen (tests/fixtures/edge-household, erzeugt von
 * generate.mjs mit eigener Physik): Wohnung A (Miete, Etagenheizung Gas) bis zum Auszug am 14.05.2026, Haus B ab dem
 * Einzug am 10.05.2026. Die Beispielhaushalte folgen dem Modell der App und enthalten keinen dieser Fälle; jeder Test
 * prüft einen Fall Ansicht gegen Ansicht (Saldo-Karte, „Saldo heute“, Abrechnungsjahr, „Rechnung prüfen“, Jahresbericht,
 * /api/summary, KI) und gegen eine Handrechnung aus den Zählerständen der Datei, nicht aus dem Code der App. Heute ist
 * der 01.11.2026, der Haushalt endet am 31.10.2026. Tageskonvention: Eine Ablesung am Tag D zählt bis D − 1; liegt sie auf
 * dem letzten Tag einer Periode oder des letzten Vertrags, ist sie dessen Endstand.
 *
 * Bekannte Grenzen (CHANGELOG 3.13), hier beschrieben, nicht behoben: Arbeitspreis auf den Cent (Monatszeilen runden je
 * Monat, Abschnitte je Abschnitt: Karte und Abrechnungsjahr bis 2 Cent auseinander, gegen die Handrechnung bis 0,5 Cent je
 * Abschnitt), geschätzter Stand an einer Periodengrenze, Monatszeile einer Zählergruppe.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(BillService::class)]
final class EdgeHouseholdTest extends McpReadTestCase
{
    private const DAY = self::EDGE_TODAY;
    private const DIR = __DIR__ . '/../../fixtures/edge-household/';
    /** bis 2 Cent: Rundung je Monat gegen je Abschnitt (Bekannte Grenze), mit Rand für Gleitkomma */
    private const CENTS = 0.021;

    /** @var array<string,mixed> */
    private array $h = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->h = $this->edge();
    }

    // ── Hilfen ────────────────────────────────────────────────────────────

    /** Zählerstand der Datei am Tag (die Handrechnung liest die Datei, nicht die App). */
    private function counter(string $meterId, string $date): float
    {
        foreach ($this->h['utilities'] as $pots) {
            foreach ($pots['readings'] ?? [] as $r) if ($r['meter_id'] === $meterId && $r['date'] === $date) return (float)$r['counter'];
        }
        self::fail("kein Stand $meterId am $date");
    }

    private function target(string $u, string $id): array
    {
        return $this->meters->get($u, $id) ?? $this->meters->groupTarget($u, $id);
    }

    /** Zeile des Vertragsstatus (Saldo-Karte, „Frühere Verträge“). */
    private function card(string $u, string $id, string $contractId): array
    {
        $rows = array_column($this->consumption->contractStatus($u, $this->target($u, $id), self::DAY)['contracts'], null, 'contract_id');
        self::assertArrayHasKey($contractId, $rows);
        return $rows[$contractId];
    }

    private function period(string $u, string $id, string $from): array
    {
        $all = $this->svc(BillingPeriodService::class)->forTarget($u, $this->target($u, $id), false, self::DAY)['periods'];
        $hit = array_values(array_filter($all, fn($p) => $p['from'] === $from));
        self::assertNotEmpty($hit, "keine Periode ab $from");
        return $hit[0];
    }

    /** „Rechnung prüfen“ für [von, bis) ohne erfasste Rechnung (dieselbe Rechnung wie BillService::compare). */
    private function check(string $u, string $id, string $from, string $toExcl): array
    {
        return $this->consumption->atDate(self::DAY, fn() => $this->consumption->billBreakdown($u, $this->target($u, $id), $from, $toExcl))['totals'];
    }

    private function kiPeriod(string $u, string $id, string $from): array
    {
        foreach ($this->callTool('get_consumption', ['utility' => $u, 'meter' => $id, 'granularity' => 'billing_period'], $this->context('mcp', self::DAY))['rows'] as $r) {
            if ($r['from'] === $from) return $r;
        }
        self::fail("KI: keine Periode ab $from");
    }

    private function kiContract(string $u, string $contractId): array
    {
        foreach ($this->callTool('get_contracts', ['utility' => $u, 'include_past' => true], $this->context('mcp', self::DAY))['targets'] as $t) {
            foreach ($t['contracts'] as $c) if ($c['contract_id'] === $contractId) return $c;
        }
        self::fail("KI: kein Vertrag $contractId");
    }

    /** /api/summary: der Vertrag am Zähler (bei Mitgliedern einer Gruppe der Gruppenvertrag). */
    private function summary(string $meterId): ?array
    {
        foreach ($this->svc(SummaryService::class)->build(null, null, self::DAY)['meters'] as $m) {
            if ($m['meter_id'] === $meterId) return $m['contract'] ?? ($m['group_contract']['contract'] ?? null);
        }
        return null;
    }

    /** Zeile des Anhangs „Abrechnungsjahre“ im Jahresbericht (Art, Jahr des Periodenendes). @return list<string> */
    private function appendixRow(string $u, int $year): array
    {
        $model = $this->svc(ReportModelService::class)->build($year, ['today' => self::DAY, 'billing' => true]);
        $sec = array_values(array_filter((array)($model['billing'] ?? []), fn($s) => $s['utility'] === $u));
        self::assertCount(1, $sec, "Anhang $u $year");
        self::assertCount(1, $sec[0]['rows']);
        return $sec[0]['rows'][0] + ['notes' => $sec[0]['notes']];
    }

    private function eur(float $v): string
    {
        return $this->i18n->number($v, 2);
    }

    /** Tagesanteil eines Monatsbetrags über [von, bis) nach Kalendertagen je Monat (eigene Rechnung). */
    private static function monthsShare(string $from, string $toExcl): float
    {
        $share = 0.0;
        for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') < $toExcl; $d = $d->modify('first day of next month')) {
            $next = min($d->modify('first day of next month')->format('Y-m-d'), $toExcl);
            $share += (new \DateTimeImmutable($d->format('Y-m-d')))->diff(new \DateTimeImmutable($next))->days / (int)$d->format('t');
        }
        return $share;
    }

    // ── Der Haushalt ──────────────────────────────────────────────────────

    public function testTheFixtureIsWhatTheGeneratorWrites(): void
    {
        exec('command -v node 2>/dev/null', $which, $found);
        if ($found !== 0) self::markTestSkipped('node fehlt');
        exec('node ' . escapeshellarg(self::DIR . 'generate.mjs') . ' --check 2>&1', $out, $rc);
        self::assertSame(0, $rc, implode("\n", $out));
    }

    /** Jeder Datensatz besteht die Prüfung, die Backup-Import und Bestand beim Start anlegen (Support\FieldRules). */
    public function testEveryRecordPassesTheFieldRules(): void
    {
        $problems = [];
        foreach ($this->h['utilities'] as $u => $pots) {
            foreach ($pots as $pot => $rows) {
                if (!isset(FieldRules::FIELDS[$pot])) continue;
                foreach ($rows as $i => $row) foreach (FieldRules::problems($row, $pot) as $p) $problems[] = "$u/$pot#$i $p";
            }
        }
        foreach ($this->h['tenancies'] as $i => $t) foreach (FieldRules::problems($t, 'tenancies') as $p) $problems[] = "tenancies#$i $p";
        self::assertSame([], $problems);
    }

    /** Der Haushalt hat, was die Doku verspricht, und folgt nicht dem Modell der App. */
    public function testTheHouseholdHasEveryCase(): void
    {
        $c = [];
        foreach ($this->h['utilities'] as $pots) foreach ($pots['contracts'] ?? [] as $row) $c[$row['id']] = $row;
        self::assertSame(['2026-05-14', false], [$c['c_gas_a']['end'], $c['c_gas_a']['auto_renews']], 'Auszug ohne Folgevertrag');
        self::assertSame(['2026-05-10', 'g_b_dt'], [$c['c_b_dt']['start'], $c['c_b_dt']['meter_group_id']], 'Einzug am 10., Gruppe');
        self::assertNotEquals($c['c_b_dt']['working_prices_by_meter']['m_b_ht'], $c['c_b_dt']['working_prices_by_meter']['m_b_nt'], 'Preise je Zählwerk');
        self::assertSame(3, $c['c_wasser_b']['advance_payments'][0]['every_months'], 'Abschlag mit Terminen');
        self::assertSame('2026-08-01', $c['c_b_wb']['working_prices'][0]['from'], 'Arbeitspreis erst später');
        self::assertSame([1, 3], [$c['c_b_dt']['notice_period_months'], $c['c_b_wb']['notice_period_months']]);
        self::assertSame(['2026-05-14', 71], [$this->h['tenancies'][0]['end'], $this->h['tenancies'][0]['wohnflaeche_m2']]);
        self::assertLessThan(15.0, $this->h['params']['A_HEAT_LIMIT_C'], 'eigene Heizgrenze, nicht die 15 °C der App');
    }

    // ── Einzug mitten im Monat, Zählergruppe HT/NT ────────────────────────

    /**
     * Einzug am 10.05.2026, Gruppenvertrag 118 €/Monat, Grundpreis 14,90 €/Monat; gemessen bis zum frühesten letzten Stand
     * der Mitglieder (NT 28.09., zählt bis 27.09.). Von Hand: Tage 10.05. bis 27.09. = 22/31 + 3 + 27/30 Monate
     *   Abschläge 118 € × 4,6097 = 543,94 €; Grundpreis 14,90 € × 4,6097 = 68,68 €
     *   HT (HT 28.09. − 10.05.) × 34,15 ct + NT (NT 28.09. − 10.05.) × 23,80 ct, aus den Ständen der Datei
     */
    public function testMovingInMidMonthWithAPeakAndOffPeakGroup(): void
    {
        $share = self::monthsShare('2026-05-10', '2026-09-28');
        $ht = $this->counter('m_b_ht', '2026-09-28') - $this->counter('m_b_ht', '2026-05-10');
        $nt = $this->counter('m_b_nt', '2026-09-28') - $this->counter('m_b_nt', '2026-05-10');
        $energy = $ht * 0.3415 + $nt * 0.238;
        $hand = round($energy, 2) + round(14.9 * $share, 2) - round(118 * $share, 2);

        $card = $this->card('strom', 'g_b_dt', 'c_b_dt');
        $bm = $card['balance_measured'];
        $year = $this->period('strom', 'g_b_dt', '2026-05-10');
        self::assertSame(['2026-05-10', '2026-09-28', '2026-09-27', 141], [$bm['from'], $bm['until'], $year['as_of'], $year['days']]);
        self::assertSame([round(118 * $share, 2), round(14.9 * $share, 2)], [$bm['advance'], $bm['base']], 'Karte: Abschlag und Grundpreis anteilig');
        self::assertSame([$bm['advance'], $bm['base']], [$year['advances'], $year['fixed_cost']], 'Abrechnungsjahr = Karte');
        self::assertEqualsWithDelta($energy, $bm['energy_cost'], self::CENTS, 'HT und NT mit ihrem Preis');
        self::assertEqualsWithDelta($bm['energy_cost'], $year['energy_cost'], self::CENTS, 'Arbeitspreis auf den Cent (Bekannte Grenze)');
        self::assertEqualsWithDelta($hand, $bm['balance'], self::CENTS);
        self::assertSame($bm['balance'], $year['balance'], 'Saldo: Karte = Abrechnungsjahr');
        $check = $this->check('strom', 'g_b_dt', '2026-05-10', '2026-09-28');
        self::assertSame([$year['energy_cost'], $year['fixed_cost']], [$check['energy_cost'], $check['fixed_cost']], '„Rechnung prüfen“ = Abrechnungsjahr');
        $ki = $this->kiPeriod('strom', 'g_b_dt', '2026-05-10');
        self::assertSame([$year['balance'], '2026-09-28'], [$ki['balance'], $ki['reading_on'] ?? null], 'KI: Saldo und der Tag der Ablesung');
        self::assertSame($bm['balance'], (float)$this->kiContract('strom', 'c_b_dt')['status']['balance_measured']['value'], 'KI-Vertrag = Karte');
        foreach (['m_b_ht', 'm_b_nt'] as $mid) {
            self::assertSame([$bm['balance'], $card['projected_end_balance']], [$this->summary($mid)['measured_balance'], $this->summary($mid)['projected_end_balance']], "/api/summary $mid");
        }
    }

    /**
     * Bekannte Grenze „Monatszeile einer Zählergruppe“: Im Oktober ist NT nicht mehr abgelesen (zuletzt 28.09.); die
     * Monatszeile der Gruppe zeigt dann nur HT, ohne Kennzeichen. Saldo und Abrechnungsjahr enden dagegen am 27.09.
     */
    public function testTheGroupMonthWithoutOneMemberShowsOnlyTheOther(): void
    {
        $months = array_column($this->consumption->forMeter('strom', $this->target('strom', 'g_b_dt'), null, self::DAY), null, 'ym');
        $htOnly = array_column($this->consumption->forMeter('strom', $this->target('strom', 'm_b_ht'), null, self::DAY), null, 'ym')['2026-10'];
        self::assertEqualsWithDelta((float)$htOnly['kwh'], (float)$months['2026-10']['kwh'], 0.05, 'Oktober der Gruppe = nur HT');
        self::assertArrayNotHasKey('price_missing', $months['2026-10']);
        self::assertSame('2026-09-27', $this->period('strom', 'g_b_dt', '2026-05-10')['as_of']);
    }

    // ── Vertragsende ohne Folgevertrag ────────────────────────────────────

    /**
     * Gas A, Ende 14.05.2026, Schlussablesung am selben Tag (Endstand). Von Hand aus den Ständen:
     *   (Stand 14.05. − Stand 01.01.) m³ × 10,27 kWh/m³ × 9,86 ct; Grundpreis 13,20 € × (4 + 14/31); Abschläge 84 € × (4 + 14/31)
     */
    public function testAContractEndingWithAFinalReadingOnItsLastDay(): void
    {
        $kwh = ($this->counter('m_gas_a', '2026-05-14') - $this->counter('m_gas_a', '2026-01-01')) * 10.27;
        $share = 4 + 14 / 31;
        $hand = round($kwh * 0.0986, 2) + round(13.2 * $share, 2) - round(84 * $share, 2);

        $card = $this->card('gas', 'm_gas_a', 'c_gas_a');
        $bm = $card['balance_measured'];
        $year = $this->period('gas', 'm_gas_a', '2026-01-01');
        self::assertSame(['2026-05-14', 134], [$year['as_of'], $year['days']], 'der letzte Vertragstag zählt');
        self::assertEqualsWithDelta($hand, $bm['balance'], self::CENTS, 'Karte');
        self::assertEqualsWithDelta($bm['balance'], $year['balance'], self::CENTS, 'Abrechnungsjahr (Bekannte Grenze: Cent)');
        self::assertSame($bm['balance'], $card['current_balance'], '„Saldo heute“ eines beendeten Vertrags = Schlusssaldo (bis dahin ein geschätzter Tag mehr)');
        self::assertSame($bm['cost'], $card['cost_to_date'], 'Kosten in „Frühere Verträge“');
        self::assertSame($card['current_balance'], (float)$this->kiContract('gas', 'c_gas_a')['status']['balance_today']['value'], 'KI balance_today');
        $check = $this->check('gas', 'm_gas_a', '2026-01-01', '2026-05-15');
        self::assertSame([$year['energy_cost'], $year['fixed_cost']], [$check['energy_cost'], $check['fixed_cost']], '„Rechnung prüfen“ der Schlussrechnung');
        self::assertEqualsWithDelta($kwh, $year['kwh'], 0.1);
        $ki = $this->kiPeriod('gas', 'm_gas_a', '2026-01-01');
        self::assertSame([$year['balance'], '2026-05-14'], [$ki['balance'], $ki['reading_on'] ?? null], 'KI: Endstand am letzten Vertragstag');
        $row = $this->appendixRow('gas', 2026);
        self::assertSame([$this->eur($year['cost']), $this->eur($year['advances']), $this->eur($year['balance'])], [$row[3], $row[5], $row[6]], 'Jahresbericht, Anhang');
    }

    /**
     * Strom A, Ende 14.05.2026, Übergabe-Ablesung erst am 19.05. Das Abrechnungsjahr endet mit dem Vertrag (bis dahin am
     * 18.05., 4 Tage und deren Menge mehr), wie Karte und Schlussrechnung. Von Hand: Grundpreis 11,40 € × (4 + 14/31),
     * Abschläge 52 € × (4 + 14/31); die Menge bis 14.05. ist aus dem Ableseabstand 28.03. bis 19.05. verteilt.
     */
    public function testAContractEndingDaysBeforeTheFinalReading(): void
    {
        $share = 4 + 14 / 31;
        $card = $this->card('strom', 'm_strom_a', 'c_strom_a');
        $year = $this->period('strom', 'm_strom_a', '2026-01-01');
        $check = $this->check('strom', 'm_strom_a', '2026-01-01', '2026-05-15');
        self::assertSame(['2026-05-14', 134, true], [$year['as_of'], $year['days'], ($year['contract_end'] ?? null) === '2026-05-14']);
        self::assertSame([round(11.4 * $share, 2), round(52 * $share, 2)], [$year['fixed_cost'], $year['advances']]);
        self::assertSame([$check['kwh'], $check['energy_cost']], [$year['kwh'], $year['energy_cost']], 'Menge und Kosten = Schlussrechnung 01.01. bis 14.05.');
        self::assertSame(['2026-05-15', $year['balance'], $year['balance']], [$card['balance_measured']['until'], $card['balance_measured']['balance'], $card['current_balance']]);
        // v3.13.0 — die Karte nennt dieselbe Menge wie das Abrechnungsjahr („… Monate · … kWh“), nicht die bis zur Übergabe
        self::assertEqualsWithDelta($year['kwh'], $card['actual_kwh'], 0.05, 'Karte = Abrechnungsjahr (Menge)');
        $ki = $this->kiPeriod('strom', 'm_strom_a', '2026-01-01');
        self::assertSame([$year['kwh'], true], [$ki['value'], $ki['end_distributed'] ?? false], 'KI: keine Ablesung am Ende');
        self::assertArrayNotHasKey('reading_on', $ki);
        // die Summe der Art rechnet weiter: Gruppe und Wallbox laufen
        $all = $this->svc(BillingPeriodService::class)->forUtility('strom', false, self::DAY)['periods'];
        self::assertGreaterThan('2026-05-14', (string)end($all)['as_of']);
    }

    /**
     * v3.13.0 — Die Summe der Art Strom 2026 ist die Summe ihrer Ziele, jedes bis zu seiner eigenen Grenze: Strom A bis zum
     * Vertragsende 14.05. (nicht bis zur Übergabe), Gruppe B bis zum frühesten Stand ihrer Zählwerke (NT 28.09.), die
     * Wallbox bis zu ihrer Ablesung. Bis dahin rechnete die Art alle Zähler bis zum spätesten Stand (HT 30.10.): Grundpreis
     * und Abschläge der Gruppe liefen über den 27.09. hinaus, die Menge von NT nicht.
     */
    public function testTheUtilitySumIsTheSumOfItsTargets(): void
    {
        $in = fn(array $ps) => array_values(array_filter($ps, fn($p) => $p['from'] <= '2026-06-01' && '2026-06-01' <= $p['until']))[0];
        $art = $in($this->svc(BillingPeriodService::class)->forUtility('strom', false, self::DAY)['periods']);
        $parts = array_map(fn(string $id) => $in($this->svc(BillingPeriodService::class)->forTarget('strom', $this->target('strom', $id), false, self::DAY)['periods']),
            ['m_strom_a', 'g_b_dt', 'm_b_wb']);
        foreach (['advances', 'fixed_cost', 'energy_cost', 'balance'] as $k) {
            self::assertEqualsWithDelta(array_sum(array_column($parts, $k)), $art[$k], self::CENTS, "Strom 2026 = Σ Ziele ($k)");
        }
        self::assertEqualsWithDelta(array_sum(array_column($parts, 'kwh')), $art['measured_kwh'], 0.15, 'Menge = Σ Ziele');
        self::assertSame(max(array_column($parts, 'as_of')), $art['as_of'], 'bis zum spätesten Ziel');
    }

    /**
     * v3.13.0 — Jahresbericht 2026: Der Wallbox fehlt im Juni der Arbeitspreis. Der Bericht nennt für Strom „Preis fehlt“
     * statt Energiekosten und verspricht für die Wallbox kein Guthaben; das Mietverhältnis Wohnung A (nur Vorauszahlung, keine
     * Preise) nennt kein Guthaben aus der ganzen Vorauszahlung.
     */
    public function testTheReportWithAMissingPriceAndATenancyWithoutPrices(): void
    {
        $m = $this->svc(ReportModelService::class)->build(2026, ['today' => self::DAY]);
        self::assertNotContains('strom', array_column($m['money']['by_utility'], 'utility'), 'Strom ohne Energiekosten');
        self::assertStringContainsString('Preis fehlt', $m['money']['total']['sentence']);
        $wb = array_values(array_filter($m['money']['balances'], fn($b) => str_contains((string)$b['label'], 'Wallbox')))[0] ?? null;
        self::assertNotNull($wb);
        self::assertSame([null, true], [$wb['projected_end_balance'], $wb['price_missing'] ?? null]);
        $page = array_values(array_filter($m['pages'], fn($p) => $p['utility'] === 'strom'))[0];
        self::assertSame('price_missing', $page['cost']['basis']);
        $tenancy = $m['topics']['tenancy'];
        self::assertNull($tenancy['projected_result_eur'], 'Mietverhältnis ohne Preise: kein Ergebnis');
        self::assertStringContainsString('Die Kosten kennt die App nicht', implode(' ', $tenancy['sentences']));
    }

    // ── Abschläge: Termine und ganze Rate ─────────────────────────────────

    /** Wasser B: 147 € alle drei Monate am 15. (15.05., 15.08., 15.11.); bis zur letzten echten Ablesung (03.10.) zwei Raten. */
    public function testAnAdvanceWithDueDates(): void
    {
        $card = $this->card('wasser', 'm_wasser_b', 'c_wasser_b');
        $year = $this->period('wasser', 'm_wasser_b', '2026-05-10');
        self::assertSame([294.0, 294.0], [$card['balance_measured']['advance'], $year['advances']], '2 × 147 €');
        self::assertSame('2026-11-15', $card['next_advance_dates'][0], 'nächster Termin');
        self::assertSame(3, $card['current_advance_every_months']);
    }

    /**
     * Ganze Rate am Fälligkeitstag 12 (Einstellung für den Haushalt): Gruppe B 12.05. bis 12.09. = 5 × 118 € = 590 €; Gas A
     * und Strom A bis 14.05. je fünf Raten (5 × 84 € = 420 €, 5 × 52 € = 260 €); Wallbox 12.06. bis 12.10. = 5 × 32 € = 160 €.
     * Ein beendeter Vertrag hat keine nächsten Termine.
     */
    public function testWholeRatesOnTheDueDay(): void
    {
        $this->settings->set(['advance_counting' => 'due_day', 'advance_due_day' => 12]);
        foreach ([['strom', 'g_b_dt', 'c_b_dt', '2026-05-10', 590.0], ['gas', 'm_gas_a', 'c_gas_a', '2026-01-01', 420.0],
                  ['strom', 'm_strom_a', 'c_strom_a', '2026-01-01', 260.0], ['strom', 'm_b_wb', 'c_b_wb', '2026-06-01', 160.0]] as [$u, $id, $cid, $from, $want]) {
            $card = $this->card($u, $id, $cid);
            self::assertSame([$want, $want], [$card['balance_measured']['advance'], $this->period($u, $id, $from)['advances']], "$cid: Karte = Abrechnungsjahr");
        }
        self::assertSame([], $this->card('gas', 'm_gas_a', 'c_gas_a')['next_advance_dates'] ?? [], 'beendet: keine nächsten Termine');
        self::assertSame('2026-11-12', $this->card('strom', 'g_b_dt', 'c_b_dt')['next_advance_dates'][0]);
    }

    // ── Schätzungen ───────────────────────────────────────────────────────

    /** Wasser B: echt zuletzt am 03.10., geschätzt am 31.10.; Abrechnungsjahr und Karte enden an der echten Ablesung. */
    public function testAnEstimatedReadingAfterTheLastRealOne(): void
    {
        $card = $this->card('wasser', 'm_wasser_b', 'c_wasser_b');
        $year = $this->period('wasser', 'm_wasser_b', '2026-05-10');
        self::assertSame(['2026-10-03', '2026-10-03', '2026-10-02'], [$card['measured_until'], $card['balance_measured']['until'], $year['as_of']]);
        self::assertEqualsWithDelta($this->counter('m_wasser_b', '2026-10-03') - $this->counter('m_wasser_b', '2026-05-10'), $year['m3'], 0.005);
        self::assertGreaterThan($card['balance_measured']['cost'], $card['cost_to_date'], '„Saldo heute“ schätzt die Tage danach');
    }

    /**
     * Bekannte Grenze „geschätzter Stand an einer Periodengrenze“: Strom A, zum 31.12.2024 vom Versorger geschätzt (7 % zu
     * wenig), echt wieder am 03.01.2025. Die Schätzung teilt die Menge zwischen 2024 und 2025; die Summe beider hängt nicht
     * an ihr. Ein anderer geschätzter Wert verschiebt Menge von einem Jahr ins andere. Von Hand, Schätzung 60 kWh höher:
     * Abstand 22.06. bis 31.12. + 60 kWh; Abstand 31.12. bis 03.01. − 60 kWh über drei Tage, davon der 31.12. (der Stand am
     * Tag D ist der Beginn von D) in 2024: 2024 + 60 − 20 = + 40 kWh, 2025 − 40 kWh.
     */
    public function testAnEstimateOnAPeriodBoundarySplitsTheQuantity(): void
    {
        $years = fn() => array_map(fn($from) => (float)$this->period('strom', 'm_strom_a', $from)['kwh'], ['2024-01-01', '2025-01-01']);
        [$a24, $a25] = $years();
        $rows = (array)$this->store->read('strom/readings.json', []);
        foreach ($rows as $i => $r) if ($r['date'] === '2024-12-31') $rows[$i]['counter'] = (float)$r['counter'] + 60.0;
        $this->store->write('strom/readings.json', $rows);
        [$b24, $b25] = $years();
        self::assertEqualsWithDelta($a24 + $a25, $b24 + $b25, 0.2, 'die Summe beider Jahre bleibt');
        self::assertEqualsWithDelta(40.0, $b24 - $a24, 0.2, 'die Schätzung verschiebt die Menge ins Jahr 2024');
        self::assertEqualsWithDelta(-40.0, $b25 - $a25, 0.2);
        self::assertSame('estimated', $this->kiPeriod('strom', 'm_strom_a', '2024-01-01')['basis'], 'KI nennt das Jahr geschätzt');
    }

    // ── Gartenzähler ──────────────────────────────────────────────────────

    /**
     * Garten zuletzt am 27.10., Hauptzähler zuletzt echt am 03.10. Abgezogen wird nur die Gartenmenge der Tage, die der
     * Hauptzähler gemessen hat; zwischen zwei Gartenständen nach Tagen (Tagesrate). Von Hand bis 03.10.:
     *   Garten (23.08. − 10.05.) + (27.10. − 23.08.) × 41/65; Trinkwasser × 2,37 €, (Haus − Garten) × 3,12 €
     */
    public function testAGardenMeterReadLaterThanTheMainMeter(): void
    {
        $house = $this->counter('m_wasser_b', '2026-10-03') - $this->counter('m_wasser_b', '2026-05-10');
        $garden = $this->counter('m_garten_b', '2026-08-23') - $this->counter('m_garten_b', '2026-05-10')
            + ($this->counter('m_garten_b', '2026-10-27') - $this->counter('m_garten_b', '2026-08-23')) * 41 / 65;
        $energy = $house * 2.37 + ($house - $garden) * 3.12;
        $year = $this->period('wasser', 'm_wasser_b', '2026-05-10');
        $card = $this->card('wasser', 'm_wasser_b', 'c_wasser_b')['balance_measured'];
        self::assertEqualsWithDelta($energy, $year['energy_cost'], self::CENTS, 'Abrechnungsjahr');
        self::assertEqualsWithDelta($year['energy_cost'], $card['energy_cost'], self::CENTS, 'Karte (Bekannte Grenze: Cent)');
        self::assertSame(round(4.8 * self::monthsShare('2026-05-10', '2026-10-03'), 2), $year['fixed_cost']);
        $check = $this->check('wasser', 'm_wasser_b', '2026-05-10', '2026-10-03');
        self::assertSame([$year['energy_cost'], $year['m3']], [$check['energy_cost'], $check['m3']], '„Rechnung prüfen“ = Abrechnungsjahr');
        self::assertSame($year['balance'], $this->kiPeriod('wasser', 'm_wasser_b', '2026-05-10')['balance']);
        self::assertEqualsWithDelta($card['balance'], $this->summary('m_wasser_b')['measured_balance'], 0.005, '/api/summary = Karte');
    }

    // ── Preis erst später, Preis an der Ablesung ──────────────────────────

    /**
     * Wallbox: Vertrag ab 01.06., Arbeitspreis 27,40 ct erst ab 01.08.; 30,90 ct an der Ablesung vom 01.08. Juni ohne Preis
     * („Preis fehlt“), Juli trägt der Preis an der Ablesung (ein Abschnitt ohne Preis an seiner ersten Ablesung nimmt den
     * seiner letzten), ab August der Vertrag. Von Hand: Juli (01.08. − 01.07.) × 30,90 ct, August bis Oktober
     * (31.10. − 01.08.) × 27,40 ct; Grundpreis 6,50 € und Abschlag 32 € × (4 + 30/31).
     */
    public function testAPriceFromALaterDayAndAMonthCarriedByAReadingPrice(): void
    {
        $july = ($this->counter('m_b_wb', '2026-08-01') - $this->counter('m_b_wb', '2026-07-01')) * 0.309;
        $later = ($this->counter('m_b_wb', '2026-10-31') - $this->counter('m_b_wb', '2026-08-01')) * 0.274;
        $share = 4 + 30 / 31;
        $months = array_column($this->consumption->forMeter('strom', $this->target('strom', 'm_b_wb'), null, self::DAY), null, 'ym');
        self::assertTrue($months['2026-06']['price_missing'] ?? false, 'Juni: Preis fehlt');
        self::assertArrayNotHasKey('price_missing', $months['2026-07']);
        self::assertEqualsWithDelta($july, (float)$months['2026-07']['kwh_cost'], 0.005, 'Juli: Preis der Ablesung vom 01.08.');

        $card = $this->card('strom', 'm_b_wb', 'c_b_wb');
        $bm = $card['balance_measured'];
        $year = $this->period('strom', 'm_b_wb', '2026-06-01');
        $check = $this->check('strom', 'm_b_wb', '2026-06-01', '2026-10-31');
        self::assertEqualsWithDelta($july + $later, $bm['energy_cost'], self::CENTS);
        self::assertSame([$bm['energy_cost'], $bm['energy_cost']], [$year['energy_cost'], $check['energy_cost']], 'Karte = Abrechnungsjahr = „Rechnung prüfen“ (bis dahin ohne Juli)');
        self::assertSame([round(6.5 * $share, 2), round(32 * $share, 2)], [$year['fixed_cost'], $year['advances']]);
        self::assertSame($bm['balance'], $year['balance']);
        // „Preis fehlt“ (der Juni) in jeder Ansicht; die KI nennt dann keinen Betrag
        $ki = $this->kiPeriod('strom', 'm_b_wb', '2026-06-01');
        self::assertSame([true, true, true, true], [$card['price_missing'], $year['price_missing'], $ki['price_missing'] ?? false, $this->summary('m_b_wb')['price_missing'] ?? false]);
        self::assertArrayNotHasKey('cost', $ki);
        self::assertSame('no_price', $ki['balance_meaning']);
        // Jahresbericht: der Anhang zeigt „Preis fehlt“ statt Kosten und Saldo der Strom-Periode
        $row = $this->appendixRow('strom', 2026);
        self::assertSame([$this->i18n->t('priceMissing.short'), $this->i18n->t('priceMissing.short')], [$row[3], $row[6]]);
        self::assertStringContainsString(' ‡', $row[0]);
        self::assertContains('‡ ' . $this->i18n->t('priceMissing.period'), $row['notes']);
    }

    // ── Kündigungsstichtag ────────────────────────────────────────────────

    private static function monthLen(int $y, int $m): int
    {
        return [31, ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$m - 1];
    }

    /** §§ 187 Abs. 1, 188 Abs. 2, 3 BGB: Ende der Frist bei Zugang am Tag $iso (eigene Kalenderrechnung, ohne DateTime). */
    private static function forwardEnd(string $iso, int $n): string
    {
        [$y, $m, $d] = [(int)substr($iso, 0, 4), (int)substr($iso, 5, 2), (int)substr($iso, 8, 2)];
        $k = $y * 12 + ($m - 1) + $n;
        [$ty, $tm] = [intdiv($k, 12), $k % 12 + 1];
        return sprintf('%04d-%02d-%02d', $ty, $tm, min($d, self::monthLen($ty, $tm)));
    }

    /** Der späteste Zugangstag, dessen Frist spätestens am Vertragsende abläuft (rückwärts suchen, vorwärts prüfen). */
    private static function lastNoticeDay(string $end, int $n): string
    {
        $d = $end;
        while (self::forwardEnd($d, $n) > $end) {
            [$y, $m, $dd] = [(int)substr($d, 0, 4), (int)substr($d, 5, 2), (int)substr($d, 8, 2)];
            $d = $dd > 1 ? sprintf('%04d-%02d-%02d', $y, $m, $dd - 1)
                : ($m > 1 ? sprintf('%04d-%02d-%02d', $y, $m - 1, self::monthLen($y, $m - 1)) : sprintf('%04d-12-31', $y - 1));
        }
        return $d;
    }

    /**
     * Ende am Monatsletzten: Gruppe B bis 30.04.2027 mit einem Monat (Zugang 31.03. → „31.04.“ fehlt → 30.04. gewahrt:
     * 31.03.2027; bis v3.12 30.03.), Wallbox bis 30.11.2027 mit drei Monaten (Zugang 31.08. → 30.11.: 31.08.2027; bis v3.12
     * 30.08.). Vertragsstatus, KI, /api/summary, „Zu tun“ und Kündigungsbrief nennen denselben Tag.
     */
    public function testCancellationDeadlinesAtTheEndOfAMonth(): void
    {
        $letters = new LetterService(new RightsService($this->settings, $this->i18n), $this->contracts, $this->meters, $this->svc(BillService::class));
        $events = $this->svc(AgendaService::class)->events(400, self::DAY);
        foreach ([['g_b_dt', 'c_b_dt', 'm_b_ht', '2027-04-30', 1, '2027-03-31'], ['m_b_wb', 'c_b_wb', 'm_b_wb', '2027-11-30', 3, '2027-08-31']] as [$id, $cid, $mid, $end, $n, $want]) {
            self::assertSame($want, self::lastNoticeDay($end, $n), 'Vorwärtsrechnung');
            self::assertSame($want, $this->card('strom', $id, $cid)['cancel_by'], "$cid Vertragsstatus");
            self::assertSame($want, $this->kiContract('strom', $cid)['cancel_by'] ?? null, "$cid KI");
            self::assertSame($want, $this->summary($mid)['cancel_by'] ?? null, "$cid /api/summary");
            $mine = array_values(array_filter($events, fn($e) => ($e['ref']['contract_id'] ?? null) === $cid && $e['kind'] === 'cancel_by'));
            self::assertSame([$want], array_column($mine, 'date'), "$cid „Zu tun“");
            $m = $letters->render(['utility' => 'strom', 'contract_id' => $cid, 'kind' => 'termination',
                'sender' => ['Erika Muster', 'Musterweg 1', '12345 Musterstadt'], 'place' => 'Musterstadt'], self::DAY)['model'];
            self::assertSame($end, $m['defaults']['extra']['term_date'] ?? null, "$cid Brief: Termin zum Vertragsende");
        }
    }

    // ── Mietverhältnis mit Auszug, CO₂-Aufteilung im Teiljahr ─────────────

    /**
     * Wohnung A, 71 m², Etagenheizung Gas, Auszug am 14.05.2026: Teiljahr 01.01. bis 14.05. = 134 Tage. Von Hand:
     *   (Stand 14.05. − Stand 01.01.) m³ × 10,27 kWh/m³ × 0,18139 kg/kWh = Ausstoß; ÷ 71 m², auf eine Stelle
     *   Grenzen der Stufen (CO2KostAufG, Anlage) × 134/365; Kosten Ausstoß × 60 €/t × 1,19; Vermieter nach Stufe
     */
    public function testATenancyEndingInTheYearSplitsTheCo2Cost(): void
    {
        $kg = ($this->counter('m_gas_a', '2026-05-14') - $this->counter('m_gas_a', '2026-01-01')) * 10.27 * Countries::CO2_BEHG_FACTORS['gas'];
        $perM2 = round(round($kg, 1) / 71, 1);
        $limits = [12, 17, 22, 27, 32, 37, 42, 47, 52];   // Anlage CO2KostAufG: kg CO₂/m²/a, Stufen 1 bis 10
        $shares = [0, 10, 20, 30, 40, 50, 60, 70, 80, 95];
        $stage = 1;
        foreach ($limits as $l) if ($perM2 >= $l * 134 / 365) $stage++;
        $cost = round(round($kg, 1) / 1000 * 60 * 1.19, 2);

        $ten = new TenancyService($this->store, $this->meters, $this->i18n);
        $costs = new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n, $ten);
        $split = $this->consumption->atDate(self::DAY, fn() => (new Co2SplitService($costs, $ten, $this->settings, $this->i18n))->forYear(2026));
        self::assertSame(['self_supplied', 134, $perM2, $stage, $shares[$stage - 1]],
            [$split['case'], $split['days'], $split['kg_per_m2'], $split['stage'], $split['landlord_share_pct']]);
        self::assertEqualsWithDelta($kg, $split['emissions_kg'], 0.051);
        self::assertEqualsWithDelta($cost, $split['co2_cost_eur'], 0.011);
        self::assertSame(round($split['co2_cost_eur'] * $shares[$stage - 1] / 100, 2), $split['landlord_amount_eur']);
        // Ansicht gegen Ansicht: die Kennzahl je m² der CO₂-Kosten
        $m2 = $this->consumption->atDate(self::DAY, fn() => $costs->forYear(2026))['per_m2'];
        self::assertSame([$split['kg_per_m2'], $split['stage'], $split['days']], [$m2['kg'], $m2['stage'], $m2['days']]);
    }

    // ── Schaltjahr ────────────────────────────────────────────────────────

    /**
     * Gas A 2024 (366 Tage), Jahresrechnung in der Datei, Ablesungen an beiden Neujahren. Von Hand: (Stand 01.01.2025 −
     * Stand 01.01.2024) m³ × 10,27 × 11,38 ct; Grundpreis 12 × 13,20 € = 158,40 €; Abschläge 12 × 96 € = 1.152 €.
     * Bekannte Grenze „Arbeitspreis auf den Cent“: „Rechnung prüfen“ rundet je Abschnitt (hier zwölf Ablesungen), der
     * Versorger einmal; bis 0,5 Cent je Abschnitt.
     */
    public function testALeapYearBillingPeriod(): void
    {
        $kwh = ($this->counter('m_gas_a', '2025-01-01') - $this->counter('m_gas_a', '2024-01-01')) * 10.27;
        $year = $this->period('gas', 'm_gas_a', '2024-01-01');
        self::assertSame(['2024-12-31', 366, 'bill', 158.4, 1152.0], [$year['as_of'], $year['days'], $year['source'], $year['fixed_cost'], $year['advances']]);
        $check = $this->svc(BillService::class)->compare('gas', 'b_gas_a_2024');
        $sections = count($this->consumption->atDate(self::DAY, fn() => $this->consumption->billBreakdown('gas', $this->target('gas', 'm_gas_a'), '2024-01-01', '2025-01-01'))['rows']);
        self::assertEqualsWithDelta($kwh * 0.1138, $year['energy_cost'], $sections * 0.005, 'je Abschnitt gerundet');
        self::assertSame([$year['cost'], $year['advances']], [$check['ours']['total'], $check['ours']['advances']], '„Rechnung prüfen“ = Abrechnungsjahr');
        $ki = $this->kiPeriod('gas', 'm_gas_a', '2024-01-01');
        self::assertSame([$year['balance'], '2025-01-01'], [$ki['balance'], $ki['reading_on'] ?? null]);
        $row = $this->appendixRow('gas', 2024);
        self::assertSame([$this->eur($year['cost']), $this->eur($year['balance'])], [$row[3], $row[6]], 'Jahresbericht 2024, Anhang');
    }
}
