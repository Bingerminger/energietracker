<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.11.0 (D, GitHub #54) — Verträge widersprechen sich, eine Regel für Liste und Abrechnungsjahr.
 *
 * Nachgebaut ist der Befund M6 aus der Prüfung vor v3.10.0 mit erfundenen Werten: Ein älterer
 * Stromvertrag ist bis Ende 2026 eingetragen, ein späterer (2023) trägt Versorger, Tarif und Preis
 * des Gasvertrags und endet 2023 ohne Kündigung. Die App rechnet ab 2023 mit dem späteren und schreibt
 * ihn nach seinem Ende fort (Entscheidung 11a, unverändert); das Abrechnungsjahr zeigt daraus fast das
 * Dreifache der Kosten als Abschläge — bis v3.10 ohne Hinweis. Erwartungen von Hand:
 *
 *   Verbrauch 3.000 kWh je Jahr (Ablesung jeden 1.1.), Gas-Tarif am Stromzähler 9 ct, Grundpreis
 *   12 €/Monat, Abschlag 100 €/Monat → je ganzes Jahr 270 € + 144 € = 414 € Kosten, 1.200 €
 *   Abschläge, Faktor 1.200 / 414 = 2,90.
 *   Überschneidung: Der spätere endet am 31.12.2023, der ältere ist bis 31.12.2026 eingetragen:
 *   366 + 365 + 365 = 1.096 Tage danach (2024 ist ein Schaltjahr).
 *   Preis: Median der anderen Verträge des Zählers (30 und 32 ct) = 31 ct, 9 / 31 = 0,29.
 */
#[CoversClass(ContractService::class)]
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(RecommendationService::class)]
final class ContractConflictTest extends ServiceTestCase
{
    private const TODAY = '2026-06-30';
    private string $strom;
    private string $gas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strom = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => null, 'installed_on' => '2019-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($y = 2019; $y <= 2026; $y++) $rows[] = ['date' => "$y-01-01", 'counter' => 3000.0 * ($y - 2019), 'device_id' => 'd1'];
        $rows[] = ['date' => '2026-06-30', 'counter' => 22500.0, 'device_id' => 'd1'];
        $this->setReadings('strom', $this->strom, $rows);
        $this->gas = (string)$this->meters->list('gas')[0]['id'];
        $this->store->write('strom/contracts.json', [
            self::contract('c_first', $this->strom, 'Strom Basis', '2019-01-01', '2020-12-31', 30.0, 10.0, 90.0),
            self::contract('c_old', $this->strom, 'Strom Klassik', '2021-01-01', '2026-12-31', 32.0, 10.0, 95.0),
            self::contract('c_new', $this->strom, 'Gas Komfort', '2023-01-01', '2023-12-31', 9.0, 12.0, 100.0),
        ]);
        $this->store->write('gas/contracts.json', [self::contract('c_gas', $this->gas, 'Gas Komfort', '2023-01-01', null, 9.0, 12.0, 100.0)]);
    }

    private static function contract(string $id, string $meter, string $tariff, string $start, ?string $end, float $ct, float $base, float $advance): array
    {
        return ['id' => $id, 'meter_id' => $meter, 'provider' => 'Musterwerk', 'tariff_name' => $tariff, 'start' => $start, 'end' => $end,
                'working_prices' => [['from' => $start, 'ct_per_kwh' => $ct]], 'base_prices' => [['from' => $start, 'eur_per_month' => $base]],
                'advance_payments' => [['from' => $start, 'amount_eur' => $advance]], 'bonuses' => [], 'special_payments' => []];
    }

    private function periods(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption,
            new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n));
    }

    private function period(array $bp, string $from): array
    {
        foreach ($bp['periods'] as $p) if ($p['from'] === $from) return $p;
        self::fail("keine Periode ab $from");
    }

    private function recommendations(): RecommendationService
    {
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $recs->attachContractConflicts($this->contracts, $this->periods());
        return $recs->atDate(self::TODAY);
    }

    /** @return list<array<string,mixed>> */
    private function conflictRecs(RecommendationService $recs): array
    {
        return array_values(array_filter($recs->all(), fn($r) => ($r['evidence']['rule'] ?? null) === 'r_contract_conflict'));
    }

    // ── Befund ───────────────────────────────────────────────────────────

    public function testM6IsNestedAndForeignTariff(): void
    {
        $rows = $this->contracts->conflictsFor('strom');
        self::assertCount(2, $rows);
        [$nested, $foreign] = $rows;
        self::assertSame(['kind' => 'nested', 'contract_id' => 'c_new', 'other_id' => 'c_old', 'meter_id' => $this->strom, 'meter_group_id' => null,
            'from' => '2023-01-01', 'until' => '2023-12-31', 'days' => 1096, 'other_end' => '2026-12-31', 'fix_end' => '2022-12-31'], $nested);
        self::assertSame('foreign_tariff', $foreign['kind']);
        self::assertSame('c_new', $foreign['contract_id']);
        self::assertSame(['copy', 'price'], $foreign['signals']);
        self::assertSame('gas', $foreign['other_utility']);
        self::assertSame(0.29, $foreign['factor']);
        // der echte Gasvertrag ist keine Kopie: Die Kopie allein sagt nicht, welcher stimmt
        self::assertSame([], $this->contracts->conflictsFor('gas'));
    }

    /**
     * v3.11.0 (Skeptiker N13) — der fremde Tarif mit geändertem Preis: Versorger und Tarif des Gasvertrags am
     * Stromzähler, Arbeitspreis 10,5 statt 9 ct. 10,5 / 9 = 1,17 liegt im Band 0,4 bis 2,5 um den Gaspreis
     * (Signal `name`), 10,5 / 31 = 0,34 außerhalb des Bands um die eigenen Verträge (Signal `price`).
     * Gegenprobe: derselbe Tarifname mit 30 ct (30 / 9 = 3,33, ein echter Stromtarif desselben Namens): nichts.
     */
    public function testAForeignTariffWithAChangedPriceIsStillFound(): void
    {
        $rows = $this->store->read('strom/contracts.json', []);
        $rows[2]['working_prices'] = [['from' => '2023-01-01', 'ct_per_kwh' => 10.5]];
        $this->store->write('strom/contracts.json', $rows);
        $foreign = array_values(array_filter($this->contracts->conflictsFor('strom'), fn($r) => $r['kind'] === 'foreign_tariff'));
        self::assertCount(1, $foreign);
        self::assertSame(['c_new', ['name', 'price'], 'gas', 0.34], [$foreign[0]['contract_id'], $foreign[0]['signals'], $foreign[0]['other_utility'], $foreign[0]['factor']]);
        $byId = array_column($this->contracts->list('strom'), null, 'id');
        self::assertStringContainsString('Versorger und Tarif stimmen mit einem Vertrag für Gas überein, und der Arbeitspreis liegt in dessen Größenordnung.',
            $this->contracts->conflictText('strom', $foreign[0], $byId)['detail']);
        $rows[2]['working_prices'] = [['from' => '2023-01-01', 'ct_per_kwh' => 30.0]];
        $this->store->write('strom/contracts.json', $rows);
        self::assertSame([], array_values(array_filter($this->contracts->conflictsFor('strom'), fn($r) => $r['kind'] === 'foreign_tariff')));
    }

    /** Läuft der spätere über das eingetragene Ende des älteren hinaus, ist es eine Überschneidung. */
    public function testOverlapAndTolerance(): void
    {
        $a = ['id' => 'a', 'meter_id' => 'm', 'start' => '2024-01-01', 'end' => '2024-12-31'];
        $at = fn(string $bStart, ?string $bEnd = null) => ContractService::conflicts([$a, ['id' => 'b', 'meter_id' => 'm', 'start' => $bStart, 'end' => $bEnd]]);
        // 31 Tage (1.12. bis 31.12.): übliche Wechselunschärfe, kein Befund; 32 Tage: Befund
        self::assertSame([], $at('2024-12-01'));
        self::assertSame([], $at('2024-12-31', '2025-12-31'), 'ein Tag Überschneidung');
        $r = $at('2024-11-30');
        self::assertSame(['overlap', 'b', 'a', 32, '2024-11-29', '2024-12-31'], [$r[0]['kind'], $r[0]['contract_id'], $r[0]['other_id'], $r[0]['days'], $r[0]['fix_end'], $r[0]['until']]);
        // offenes Ende: Der Folgevertrag löst ab (Wechsel ohne nachgetragenes Ende) — kein Befund
        self::assertSame([], ContractService::conflicts([['id' => 'a', 'meter_id' => 'm', 'start' => '2020-01-01', 'end' => null],
            ['id' => 'b', 'meter_id' => 'm', 'start' => '2024-06-01', 'end' => '2025-05-31']]));
        // nur der erste spätere Beginn zählt (er löst ab)
        self::assertCount(1, ContractService::conflicts([['id' => 'a', 'meter_id' => 'm', 'start' => '2020-01-01', 'end' => '2029-12-31'],
            ['id' => 'b', 'meter_id' => 'm', 'start' => '2022-01-01', 'end' => null], ['id' => 'c', 'meter_id' => 'm', 'start' => '2024-01-01', 'end' => null]]));
    }

    public function testShadowsOtherTargetsAndGroups(): void
    {
        $long = ['meter_id' => 'm', 'start' => '2020-01-01', 'end' => '2029-12-31'];
        self::assertSame([], ContractService::conflicts([['id' => 'a'] + $long, ['id' => 's', 'meter_id' => 'm', 'start' => '2022-01-01', 'end' => null, 'is_shadow' => true]]), 'Angebot');
        self::assertSame([], ContractService::conflicts([['id' => 'a'] + $long, ['id' => 'b', 'meter_id' => 'm2', 'start' => '2022-01-01', 'end' => null]]), 'anderer Zähler');
        self::assertSame([], ContractService::conflicts([['id' => 'a'] + $long, ['id' => 'g', 'meter_id' => null, 'meter_group_id' => 'grp', 'start' => '2022-01-01', 'end' => null]]), 'Gruppe ist ein anderes Ziel');
        $g = ContractService::conflicts([['id' => 'g1', 'meter_id' => null, 'meter_group_id' => 'grp', 'start' => '2020-01-01', 'end' => '2029-12-31'],
            ['id' => 'g2', 'meter_id' => null, 'meter_group_id' => 'grp', 'start' => '2022-01-01', 'end' => null]]);
        self::assertSame([['overlap', 'g2', 'g1', 'grp']], array_map(fn($r) => [$r['kind'], $r['contract_id'], $r['other_id'], $r['meter_group_id']], $g));
    }

    /** Ein Dokument, das der Posteingang einer anderen Art zuordnet, zählt allein. */
    public function testDocumentOfAnotherUtility(): void
    {
        $this->store->write('inbox.json', [['id' => 'inb_1', 'attachment_id' => 'att_1', 'extraction' => ['fields' => ['utility' => ['value' => 'gas']]]],
                                           ['id' => 'inb_2', 'attachment_id' => 'att_2', 'extraction' => ['fields' => ['utility' => ['value' => 'gas']]], 'edits' => ['utility' => 'strom']]]);
        $this->store->write('strom/contracts.json', [self::contract('c1', $this->strom, 'Tarif A', '2024-01-01', null, 30.0, 10.0, 80.0) + ['attachment_ids' => ['att_1']],
                                                     self::contract('c2', $this->strom, 'Tarif B', '2023-01-01', '2023-12-31', 31.0, 10.0, 80.0) + ['attachment_ids' => ['att_2']]]);
        $rows = $this->contracts->conflictsFor('strom');
        self::assertSame([['foreign_tariff', 'c1', ['document'], 'gas']], array_map(fn($r) => [$r['kind'], $r['contract_id'], $r['signals'], $r['other_utility']], $rows),
            'att_2 hat der Nutzer zu Strom korrigiert');
    }

    // ── eine Regel für Liste und Abrechnungsjahr ─────────────────────────

    public function testCoverageOfM6(): void
    {
        $cov = $this->contracts->coverage($this->contracts->list('strom'));
        self::assertSame(['from' => '2019-01-01', 'to' => '2020-12-31', 'assumed' => false, 'assumed_from' => null, 'superseded_on' => null,
                          'renewed_until' => null, 'claimed_by' => null], $cov['c_first']);
        self::assertSame(['from' => '2021-01-01', 'to' => '2022-12-31', 'assumed' => false, 'assumed_from' => null, 'superseded_on' => '2022-12-31',
                          'renewed_until' => null, 'claimed_by' => null], $cov['c_old']);
        self::assertSame(['from' => '2023-01-01', 'to' => null, 'assumed' => true, 'assumed_from' => '2024-01-01', 'superseded_on' => null,
                          'renewed_until' => null, 'claimed_by' => ['contract_id' => 'c_old', 'until' => '2026-12-31']], $cov['c_new']);
        // gekündigt: keine Fortschreibung, ab 2024 rechnet kein Vertrag
        $list = $this->contracts->list('strom');
        $list[2]['auto_renews'] = false;
        self::assertSame(['2023-01-01', '2023-12-31', false], [$this->contracts->coverage($list)['c_new']['from'], $this->contracts->coverage($list)['c_new']['to'],
            $this->contracts->coverage($list)['c_new']['assumed']]);
    }

    /** Tag für Tag dasselbe wie resolveForDate — für M6 und Ketten mit Lücke, Kündigung und gleichem Beginn. */
    public function testCoverageMatchesResolveForDateEveryDay(): void
    {
        $lists = [
            'M6' => $this->contracts->list('strom'),
            'Lücke und Kündigung' => [
                ['id' => 'a', 'meter_id' => 'm', 'start' => '2020-03-01', 'end' => '2020-12-31', 'auto_renews' => false],
                ['id' => 'b', 'meter_id' => 'm', 'start' => '2021-02-01', 'end' => '2021-06-30'],
                ['id' => 'c', 'meter_id' => 'm', 'start' => '2021-04-15', 'end' => null],
                ['id' => 'd', 'meter_id' => 'm', 'start' => '2022-01-01', 'end' => '2022-03-31', 'auto_renews' => false],
                ['id' => 'e', 'meter_id' => 'm', 'start' => '2022-01-01', 'end' => '2022-12-31'],
            ],
        ];
        foreach ($lists as $name => $list) {
            $cov = $this->contracts->coverage($list);
            for ($d = '2018-12-25'; $d <= '2027-01-10'; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                $r = $this->contracts->resolveForDate($list, $d);
                $want = $r === null ? null : [(string)$r['contract']['id'], $r['assumed']];
                $got = null;
                foreach ($cov as $id => $c) {
                    $on = ContractService::coveredOn($c, $d);
                    if ($on !== null) { self::assertNull($got, "$name $d: zwei Verträge"); $got = [(string)$id, $on]; }
                }
                self::assertSame($want, $got, "$name $d");
            }
        }
    }

    /** Liste (Phase, Abdeckung), Vertragsstatus und Abrechnungsjahr nennen dieselben Zeiträume. */
    public function testListStatusAndBillingYearAgree(): void
    {
        $all = $this->contracts->list('strom');
        $list = [];
        foreach ($this->contracts->withPhase($all, $all, self::TODAY) as $c) $list[$c['id']] = $c;
        self::assertSame(['past', 'past', 'renewed'], [$list['c_first']['phase'], $list['c_old']['phase'], $list['c_new']['phase']]);
        self::assertSame('2022-12-31', $list['c_old']['coverage']['superseded_on']);
        self::assertSame('2024-01-01', $list['c_new']['coverage']['assumed_from']);

        $status = [];
        foreach ($this->consumption->contractStatus('strom', $this->meters->get('strom', $this->strom), self::TODAY)['contracts'] as $row) $status[$row['contract_id']] = $row;
        self::assertSame('2022-12-31', $status['c_old']['superseded_on']);
        self::assertTrue($status['c_new']['is_current'], 'fortgeschrieben = laufend');

        $bp = $this->periods()->forTarget('strom', $this->meters->get('strom', $this->strom), false, self::TODAY);
        self::assertSame([], $this->period($bp, '2023-01-01')['assumed'], '2023 rechnet er nach Eintrag');
        foreach (['2024', '2025'] as $y) {
            self::assertSame([['contract_id' => 'c_new', 'name' => 'Musterwerk · Gas Komfort', 'from' => "$y-01-01", 'until' => "$y-12-31",
                'claimed_by' => 'c_old', 'claimed_name' => 'Musterwerk · Strom Klassik', 'claimed_until' => '2026-12-31']], $this->period($bp, "$y-01-01")['assumed']);
        }
        // die laufende Periode: angenommen ab ihrem Beginn bis zum eingetragenen Ende des älteren
        self::assertSame(['2026-01-01', '2026-12-31'], [$this->period($bp, '2026-01-01')['assumed'][0]['from'], $this->period($bp, '2026-01-01')['assumed'][0]['until']]);
        // dieselben Tage wie die Abdeckung der Liste
        self::assertSame($list['c_new']['coverage']['assumed_from'], $this->period($bp, '2024-01-01')['assumed'][0]['from']);
    }

    public function testAdvancesSeveralTimesTheCost(): void
    {
        $bp = $this->periods()->forTarget('strom', $this->meters->get('strom', $this->strom), false, self::TODAY);
        $p = $this->period($bp, '2024-01-01');
        // v3.11.0 (Skeptiker N13) — mit dem Vertrag, der die Periode abrechnet
        self::assertSame([414.0, 1200.0, ['factor' => 2.9, 'contract_id' => 'c_new']], [$p['cost'], $p['advances'], $p['advance_vs_cost']]);
        // 2022 nach dem eigenen Vertrag: 960 € + 120 € Kosten, 1.140 € Abschläge — plausibel
        self::assertNull($this->period($bp, '2022-01-01')['advance_vs_cost']);
        self::assertNull($this->period($bp, '2026-01-01')['advance_vs_cost'], 'laufend');
        // für Empfehlung und Agenda nur die jüngste abgeschlossene Periode seit dem Stichtag (Antwortzeit, jede Anfrage);
        // seit N13 landen ältere Perioden desselben Vertrags im selben Eintrag, die Empfehlung allein zeigt das nicht
        $closed = $this->periods()->closedPeriods('strom', $this->meters->get('strom', $this->strom), '2022-06-01', self::TODAY);
        self::assertSame(['2025-01-01'], array_column($closed, 'from'));
    }

    // ── Wege: Empfehlung, „Zu tun“, Vertragsliste, Speichern ────────────

    public function testRecommendationAgendaAndDismiss(): void
    {
        $recs = $this->recommendations();
        $conf = $this->conflictRecs($recs);
        // v3.11.0 (Skeptiker N13) — „Abschläge rund …-mal so hoch“ hat dieselbe Ursache wie der Widerspruch (Vertrag
        // c_new): ein Eintrag, der Faktor steht im Widerspruch mit (jüngste abgeschlossene Periode 2025)
        self::assertSame(['nested', 'foreign_tariff'], array_column(array_column($conf, 'evidence'), 'kind'));
        self::assertSame(['from' => '2025-01-01', 'until' => '2025-12-31', 'factor' => 2.9], $conf[0]['evidence']['advance_vs_cost']);
        self::assertSame(['warning', 'warning'], array_column($conf, 'severity'));
        self::assertStringContainsString(' Dazu passt: Im Abrechnungsjahr 01.01.2025 bis 31.12.2025 stehen', $conf[0]['detail']);
        self::assertStringContainsString('rund 2,9-mal so viel.', $conf[0]['detail']);
        // 2028: die jüngste abgeschlossene Periode (2027) hat keine Ablesung, also keine Kosten — kein Befund
        self::assertSame(['nested', 'foreign_tariff'], array_column(array_column($this->conflictRecs($recs->atDate('2028-02-01')), 'evidence'), 'kind'));
        $recs->atDate(self::TODAY);
        self::assertSame('vertrag', $conf[0]['category']);
        self::assertSame('Strom: Verträge widersprechen sich', $conf[0]['title']);
        self::assertStringStartsWith('„Musterwerk · Gas Komfort“ endet am 31.12.2023, „Musterwerk · Strom Klassik“ ist bis 31.12.2026 eingetragen. '
            . 'Die App rechnet danach mit „Musterwerk · Gas Komfort“ weiter. Welcher Vertrag gilt danach?', $conf[0]['detail']);
        self::assertStringContainsString('Versorger, Tarif und Arbeitspreis stimmen mit einem Vertrag für Gas überein.', $conf[1]['detail']);
        self::assertSame('2022-12-31', $conf[0]['evidence']['fix_end']);

        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n);
        $all = array_values(array_filter($agenda->events(90, self::TODAY), fn($e) => $e['kind'] === 'contract_conflict'));
        self::assertCount(2, $all, 'eine Ursache, ein Eintrag (Skeptiker N13)');
        $ev = array_values(array_filter($all, fn($e) => $e['ref']['recommendation_id'] === $conf[0]['id']));
        self::assertTrue($ev[0]['due_now']);
        self::assertSame(['nested', 'c_new', 'c_old'], [$ev[0]['ref']['conflict'], $ev[0]['ref']['contract_id'], $ev[0]['ref']['other_id']]);
        self::assertSame($conf[0]['title'], $ev[0]['title']);
        self::assertSame('#/utility/strom/contracts', $ev[0]['href']);

        // „Beides stimmt“ = ausblenden: weg aus Empfehlungen und „Zu tun“, an der Karte als ausgeblendet
        $recs->dismiss($conf[0]['id'], '2099-12-31');
        self::assertNotContains($conf[0]['id'], array_column($this->conflictRecs($recs), 'id'));
        self::assertNotContains($conf[0]['id'], array_column(array_column($agenda->events(90, self::TODAY), 'ref'), 'recommendation_id'));
        $card = array_values(array_filter($this->contracts->withConflicts('strom', $this->contracts->list('strom')), fn($c) => $c['id'] === 'c_old'))[0];
        self::assertSame([[$conf[0]['id'], true]], array_map(fn($r) => [$r['recommendation_id'], $r['dismissed']], $card['conflicts']));
    }

    /** Das Ende des älteren auf den Vortag des späteren setzen: Der Befund verschwindet, die Zahlen bleiben. */
    public function testFixingTheEndKeepsTheNumbers(): void
    {
        $before = $this->period($this->periods()->forTarget('strom', $this->meters->get('strom', $this->strom), false, self::TODAY), '2025-01-01');
        $saved = $this->contracts->withConflictWarnings('strom', $this->contracts->update('strom', 'c_old', ['end' => '2022-12-31']));
        self::assertArrayNotHasKey('warnings', $saved);
        self::assertSame(['foreign_tariff'], array_column($this->contracts->conflictsFor('strom'), 'kind'), 'der fremde Tarif bleibt eine Frage');
        $after = $this->period($this->periods()->forTarget('strom', $this->meters->get('strom', $this->strom), false, self::TODAY), '2025-01-01');
        self::assertSame([$before['cost'], $before['advances'], $before['balance']], [$after['cost'], $after['advances'], $after['balance']]);
        self::assertSame([], $after['assumed'], 'nicht mehr beansprucht: eine gewöhnliche Verlängerung');
        // ein Ende vor der Fortschreibung beansprucht nichts (auch in der Liste)
        $cov = $this->contracts->coverage($this->contracts->list('strom'))['c_new'];
        self::assertSame(['2024-01-01', null], [$cov['assumed_from'], $cov['claimed_by']]);
    }

    /** Die Doku nennt die Schwellen, mit denen gerechnet wird (DE und EN, API und Ansichten). */
    public function testDocsNameTheThresholds(): void
    {
        self::assertSame([31, [0.4, 2.5], 2.0], [ContractService::OVERLAP_TOLERANCE_DAYS, ContractService::PRICE_BAND, ContractService::ADVANCE_COST_FACTOR]);
        $root = dirname(__DIR__, 3);
        foreach (['docs/referenz/api.md' => ['`OVERLAP_TOLERANCE_DAYS` (31)', '`PRICE_BAND` (0,4 bis 2,5)', '`ContractService::ADVANCE_COST_FACTOR` (2)'],
                  'docs/en/referenz/api.md' => ['`OVERLAP_TOLERANCE_DAYS` (31)', '`PRICE_BAND` (0.4 to 2.5)', '`ContractService::ADVANCE_COST_FACTOR` (2)'],
                  'docs/referenz/ansichten.md' => ['mehr als 31 Tage vor dem eingetragenen Ende', "0,4- bis\n2,5-Fachen", 'mindestens doppelt so hoch'],
                  'docs/en/referenz/ansichten.md' => ["more than 31 days before the\nentered end", "outside 0.4 to\n2.5 times", "at\nleast twice as high"]] as $file => $needles) {
            $text = (string)file_get_contents("$root/$file");
            foreach ($needles as $needle) self::assertStringContainsString($needle, $text, $file);
        }
    }

    /** Beim Speichern ein Hinweis, nicht blockierend (Lektion 45). */
    public function testSavingWarnsWithoutBlocking(): void
    {
        $saved = $this->contracts->withConflictWarnings('strom', $this->contracts->update('strom', 'c_new', ['notes' => 'nachgetragen']));
        self::assertContains('contract_conflict', $saved['warnings']);
        self::assertSame(['nested', 'foreign_tariff'], array_column($saved['conflicts'], 'kind'));
        self::assertSame('nachgetragen', $this->contracts->get('strom', 'c_new')['notes']);
    }
}
