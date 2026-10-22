<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\DraftApplier;
use Energietracker\Services\Inbox\HouseholdDraft;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxDraftService;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\DraftFixture;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 3.7.1 — der Entwurf auf einen bestehenden Haushalt (ACC-Probe 2026-10-22,
 * hier nur erfunden): Der Bestand führt den Gaszähler ohne Nummer und ohne
 * Marktlokation, mit vielen Ablesungen, Verträge ohne Vertragsnummer, den
 * Stromzähler mit Marktlokation. Ein Stapel Dokumente desselben Haushalts darf
 * nichts verdoppeln: kein zweiter Zähler, kein zweiter Vertrag; Nummer und
 * Marktlokation werden nachgetragen; was den Bestand berührt, ist eine Frage,
 * nie vorbelegt; nach dem Übernehmen sind Saldo und Bestand unverändert, außer
 * den Ergänzungen.
 *
 * Anbieter, Nummern und Beträge sind ausgedacht.
 */
#[CoversClass(HouseholdDraft::class)]
#[CoversClass(DraftApplier::class)]
#[CoversClass(InboxDraftService::class)]
final class HouseholdDraftExistingTest extends ServiceTestCase
{
    private const TODAY = '2026-01-15';
    private const GAS = 'm_gas_default';
    private const STROM = 'm_strom_default';
    /** Saldo eines Vertrags (ConsumptionService::contractStatus) */
    private const BALANCE = ['current_balance', 'projected_end_balance', 'actual_cost', 'advance_paid', 'actual_kwh', 'special_payment_net'];

    private InboxService $inbox;
    private InboxDraftService $draft;
    private BillService $bills;

    protected function setUp(): void
    {
        parent::setUp();
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings);
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, InboxFixture::provider()), $proposals,
            new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $this->draft = new InboxDraftService($this->inbox,
            new HouseholdDraft($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n, $proposals),
            new DraftApplier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $this->draft->attach(new BackupService($this->store, $this->i18n), new ActivityService($this->store, $this->settings, $this->i18n));
    }

    private function doc(string $tag, string $type, array $values): string
    {
        return DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), $tag, $type, $values);
    }

    private function build(): array
    {
        return $this->draft->draft(null, self::TODAY);
    }

    /** @return list<array<string,mixed>> */
    private static function items(array $d, string $kind, ?string $utility = null): array
    {
        return array_values(array_filter($d['items'], fn($i) => $i['kind'] === $kind && ($utility === null || $i['utility'] === $utility)));
    }

    private static function field(array $item, string $name): ?array
    {
        foreach ($item['fields'] as $f) if ($f['name'] === $name) return $f;
        return null;
    }

    /**
     * Der Bestand: Gas ohne Nummer und Marktlokation, zwei Verträge ohne
     * Nummer (Wechsel zum Jahreswechsel), drei Ablesungen, zwei gebuchte
     * Rechnungen; Strom mit Marktlokation, ein Vertrag ohne Nummer.
     */
    private function household(): void
    {
        foreach ([['2023-12-31', 10000.0], ['2024-12-31', 12000.0], ['2025-12-31', 13900.0]] as [$date, $counter]) {
            $this->readings->create('gas', ['meter_id' => self::GAS, 'date' => $date, 'counter' => $counter]);
        }
        $west = $this->contracts->create('gas', ['meter_id' => self::GAS, 'provider' => 'Westlicht Gas GmbH', 'start' => '2024-01-01', 'end' => '2024-12-31',
            'auto_renews' => false, 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 9.8]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 100.0]]]);
        $nord = $this->contracts->create('gas', ['meter_id' => self::GAS, 'provider' => 'Nordlicht Energie GmbH', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10.5]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 13.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 110.0]]]);
        foreach ([[$west, '2024-01-01', '2024-12-31', 1300.0, 1200.0, 100.0], [$nord, '2025-01-01', '2025-12-31', 1400.0, 1320.0, 80.0]] as [$c, $from, $to, $amount, $adv, $res]) {
            $b = $this->bills->create('gas', ['meter_id' => self::GAS, 'contract_id' => $c['id'], 'kind' => 'annual', 'period_from' => $from, 'period_to' => $to,
                'invoice' => ['amount_eur' => $amount, 'advances_paid_eur' => $adv, 'result_eur' => $res], 'note' => '', 'attachment_ids' => []]);
            $this->bills->book('gas', (string)$b['id']);
        }
        $this->meters->update('strom', self::STROM, ['malo_id' => DraftFixture::MALO_B]);
        $this->readings->create('strom', ['meter_id' => self::STROM, 'date' => '2025-04-01', 'counter' => 2000.0]);
        $this->readings->create('strom', ['meter_id' => self::STROM, 'date' => '2025-12-31', 'counter' => 3800.0]);
        $s = $this->contracts->create('strom', ['meter_id' => self::STROM, 'provider' => 'Nordlicht Energie GmbH', 'tariff_name' => 'Nordlicht Basis 12', 'start' => '2025-04-01',
            'working_prices' => [['from' => '2025-04-01', 'ct_per_kwh' => 31.2]], 'base_prices' => [['from' => '2025-04-01', 'eur_per_month' => 12.9]]]);
        $b = $this->bills->create('strom', ['meter_id' => self::STROM, 'contract_id' => $s['id'], 'kind' => 'annual', 'period_from' => '2025-04-01', 'period_to' => '2025-12-31',
            'invoice' => ['amount_eur' => 640.0, 'advances_paid_eur' => 600.0, 'result_eur' => 40.0], 'note' => '', 'attachment_ids' => []]);
        $this->bills->book('strom', (string)$b['id']);
    }

    /** Der Stapel desselben Haushalts: Bestätigungen und Rechnungen mit Nummern und Marktlokation. */
    private function documents(): void
    {
        $gas = ['utility' => 'gas', 'meter_number' => 'GAS-77', 'market_location' => DraftFixture::MALO_A];
        $this->doc('g-c1', 'contract_confirmation', $gas + ['supplier' => 'Westlicht Gas GmbH', 'supply_start' => '01.01.2024', 'tariff_name' => 'Westlicht Klassik',
            'working_price' => '9,80', 'base_price' => '12,00', 'base_price_period' => 'month', 'advance' => '100,00', 'contract_number' => 'WL-7']);
        $this->doc('g-i1', 'invoice', $gas + ['supplier' => 'Westlicht Gas GmbH', 'kind' => 'annual', 'period_from' => '01.01.2024', 'period_to' => '31.12.2024',
            'reading_old' => '10000', 'reading_old_date' => '31.12.2023', 'reading_new' => '12000', 'reading_new_date' => '31.12.2024',
            'total_amount' => '1300,00', 'advances_total' => '1200,00', 'result_kind' => 'due', 'result_amount' => '100,00', 'contract_number' => 'WL-7']);
        // Beginn laut Bestätigung zwei Tage nach dem Bestand (erster Liefertag): derselbe Vertrag
        $this->doc('g-c2', 'contract_confirmation', $gas + ['supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '03.01.2025', 'tariff_name' => 'Nordlicht Gas 24',
            'working_price' => '10,50', 'base_price' => '13,00', 'base_price_period' => 'month', 'advance' => '110,00', 'contract_number' => 'NG-31']);
        $this->doc('g-i2', 'invoice', $gas + ['supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2025', 'period_to' => '31.12.2025',
            'reading_old' => '12000', 'reading_old_date' => '31.12.2024', 'reading_new' => '13900', 'reading_new_date' => '31.12.2025',
            'total_amount' => '1400,00', 'advances_total' => '1320,00', 'result_kind' => 'due', 'result_amount' => '80,00', 'contract_number' => 'NG-31']);
        $strom = ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'meter_number' => 'STR-1001', 'market_location' => DraftFixture::MALO_B, 'contract_number' => 'NL-4401'];
        $this->doc('s-c1', 'contract_confirmation', $strom + ['supply_start' => '01.04.2025', 'tariff_name' => 'Nordlicht Basis 12',
            'working_price' => '31,20', 'base_price' => '12,90', 'base_price_period' => 'month']);
        $this->doc('s-i1', 'invoice', $strom + ['kind' => 'annual', 'period_from' => '01.04.2025', 'period_to' => '31.12.2025',
            'reading_old' => '2000', 'reading_old_date' => '01.04.2025', 'reading_new' => '3800', 'reading_new_date' => '31.12.2025',
            'total_amount' => '640,00', 'advances_total' => '600,00', 'result_kind' => 'due', 'result_amount' => '40,00']);
    }

    /** @return array<string,array<string,mixed>> Saldo je Vertrag (Anbieter) */
    private function balances(): array
    {
        $out = [];
        foreach (['gas' => self::GAS, 'strom' => self::STROM] as $u => $id) {
            foreach ($this->consumption->contractStatus($u, $this->meters->get($u, $id))['contracts'] as $s) {
                $out["$u:" . $s['provider']] = array_intersect_key($s, array_flip(self::BALANCE));
            }
        }
        return $out;
    }

    /** Bestand ohne die Felder, die der Entwurf ergänzen darf. */
    private function stock(): array
    {
        $out = [];
        foreach (['gas', 'strom'] as $u) {
            $out[$u] = [
                'meters' => array_map(fn($m) => ['id' => $m['id'], 'devices' => count($m['devices'] ?? [])], $this->meters->list($u)),
                'readings' => array_map(fn($r) => [$r['meter_id'], $r['date'], (float)$r['counter']], $this->readings->list($u)),
                'contracts' => array_map(fn($c) => array_diff_key($c, array_flip(['contract_number', 'customer_number', 'tariff_name', 'attachment_ids', 'updated_at'])), $this->contracts->list($u)),
                'bills' => array_map(fn($b) => array_diff_key($b, array_flip(['attachment_ids', 'updated_at'])), $this->bills->list($u)),
            ];
        }
        return $out;
    }

    public function testTheDraftOnAnExistingHouseholdDoublesNothing(): void
    {
        $this->household();
        $this->documents();
        $d = $this->build();

        // Zähler: der vorhandene, mit Nummer und Marktlokation zum Nachtragen — kein zweiter
        $m = array_column(self::items($d, 'meter'), null, 'utility');
        self::assertCount(2, $m, 'je Art ein Zähler');
        self::assertSame(['existing', self::GAS], [$m['gas']['action'], $m['gas']['meter_id']], 'der Gaszähler ohne Nummer wird übernommen');
        self::assertSame(['GAS-77', 'new', DraftFixture::MALO_A, 'new'], [self::field($m['gas'], 'serial')['value'], self::field($m['gas'], 'serial')['status'],
            self::field($m['gas'], 'malo')['value'], self::field($m['gas'], 'malo')['status']], 'Nummer und Marktlokation als Ergänzung');
        self::assertSame(['existing', self::STROM], [$m['strom']['action'], $m['strom']['meter_id']]);
        self::assertSame([], array_filter($d['items'], fn($i) => $i['kind'] === 'meter' && $i['action'] !== 'existing'), 'kein neuer Zähler');

        // Verträge: die vorhandenen, „ergänzen“ um die Vertragsnummer — keiner doppelt
        $c = self::items($d, 'contract');
        self::assertCount(3, $c);
        self::assertSame([], array_filter($c, fn($x) => $x['contract_id'] === null), 'kein neuer Vertrag');
        foreach ($c as $x) self::assertSame('update', $x['status'], (string)self::field($x, 'provider')['value']);
        $nord = array_values(array_filter($c, fn($x) => $x['utility'] === 'gas' && self::field($x, 'provider')['value'] === 'Nordlicht Energie GmbH'))[0];
        self::assertSame(['2025-01-01', 'exists'], [self::field($nord, 'start')['value'], self::field($nord, 'start')['status']], 'der Beginn des Bestands bleibt');
        self::assertSame(['NG-31', 'new'], [self::field($nord, 'contract_number')['value'], self::field($nord, 'contract_number')['status']]);
        $near = array_values(array_filter($d['findings'], fn($f) => $f['kind'] === 'start_near'));
        self::assertSame(['2025-01-03', '2025-01-01'], [$near[0]['params']['from'] ?? null, $near[0]['params']['date'] ?? null], 'zwei Tage daneben: derselbe Vertrag, begründet');

        // Preise, Abschläge, Ablesungen und Rechnungen stehen schon da
        foreach (['prices', 'advances', 'readings', 'bill'] as $kind) {
            foreach (self::items($d, $kind) as $x) self::assertSame('exists', $x['status'], "$kind {$x['utility']}: " . json_encode(array_column($x['fields'], 'status', 'name')));
        }

        // nichts am Bestand vorbelegt
        $touch = array_values(array_filter($d['items'], fn($i) => $i['actionable']));
        self::assertNotEmpty($touch);
        foreach ($touch as $x) {
            self::assertFalse($x['checked'], "{$x['kind']} {$x['utility']} ist vorbelegt");
            self::assertTrue($x['check'] && in_array('existing_update', $x['reasons'], true), "{$x['kind']}: eine Frage mit Grund");
        }

        // Übernehmen: mit allen Fragen (bestätigt) — Saldo und Bestand bleiben, nur die Ergänzungen kommen dazu
        $balance = $this->balances();
        $stock = $this->stock();
        $res = $this->draft->apply(array_column($touch, 'id'), [], [], null, self::TODAY);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        self::assertSame(0, $res['created']['meters'] + $res['created']['contracts'] + $res['created']['readings'] + $res['created']['bills'] + $res['created']['rows']);
        self::assertSame($stock, $this->stock(), 'Bestand unverändert außer den Ergänzungen');
        self::assertSame($balance, $this->balances(), 'Saldo unverändert');
        $gas = $this->meters->get('gas', self::GAS);
        self::assertSame(['GAS-77', DraftFixture::MALO_A], [$gas['devices'][0]['serial'], $gas['malo_id']], 'Nummer und Marktlokation nachgetragen');
        self::assertSame('STR-1001', $this->meters->get('strom', self::STROM)['devices'][0]['serial']);
        self::assertSame(['WL-7', 'NG-31'], array_column($this->contracts->list('gas'), 'contract_number'));

        // ein zweiter Entwurf hat nichts mehr zu tun
        self::assertSame([], array_values(array_filter($this->build()['items'], fn($i) => $i['actionable'])));
    }

    public function testADocumentThatFitsTheMeterOnlyHalfIsAQuestionNotANewMeter(): void
    {
        // ein Lieferbeginn-Brief mit einer anderen Marktlokation, ohne Nummer: früher ein leerer zweiter Stromzähler
        $this->household();
        $this->documents();
        $letter = $this->doc('s-l1', 'supply_start', ['utility' => 'strom', 'supplier' => 'Südlicht Strom GmbH', 'supply_start' => '01.01.2026',
            'meter_number' => '', 'market_location' => DraftFixture::MALO_A]);
        $d = $this->build();
        self::assertCount(1, self::items($d, 'meter', 'strom'), 'kein zweiter Stromzähler');
        self::assertSame([], array_filter(self::items($d, 'contract', 'strom'), fn($x) => $x['contract_id'] === null), 'kein neuer Vertrag daraus');
        $q = array_values(array_filter($d['missing'], fn($x) => $x['kind'] === 'meter_choice'));
        self::assertCount(1, $q, 'eine Frage');
        self::assertSame(['strom', DraftFixture::MALO_A, '#/inbox/' . $letter], [$q[0]['utility'], $q[0]['params']['meter'], $q[0]['href']]);
        self::assertNotSame('', $q[0]['params']['meters'], 'nennt den Zähler, der in Frage kommt');
        self::assertSame('meter_choice', array_column($d['documents'], 'state', 'id')[$letter], 'das Dokument bleibt offen');

        // im Posteingang dem Stromzähler zugeordnet: eine Messstelle mit ihm; der neue Vertrag überschneidet sich mit dem Bestand
        $this->inbox->update($letter, ['assignment' => ['utility' => 'strom', 'meter_id' => self::STROM, 'contract_id' => null]]);
        $d = $this->build();
        self::assertSame([], array_filter($d['missing'], fn($x) => $x['kind'] === 'meter_choice'));
        self::assertCount(1, self::items($d, 'meter', 'strom'));
        $new = array_values(array_filter(self::items($d, 'contract', 'strom'), fn($x) => $x['contract_id'] === null));
        self::assertSame([self::items($d, 'meter', 'strom')[0]['meter_key'], 'overlap', false, true],
            [$new[0]['meter_key'] ?? null, $new[0]['status'] ?? null, $new[0]['checked'] ?? null, in_array('existing_overlap', $new[0]['reasons'] ?? [], true)],
            'am Stromzähler; eine Überschneidung ist eine Frage');
    }

    public function testAMeterWithAnotherNumberIsAQuestion(): void
    {
        // das laufende Gerät trägt eine andere Nummer: vielleicht ein Tausch — kein stilles Übernehmen, kein neuer Zähler
        $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => 'GAS-ALT', 'installed_on' => '2020-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]], self::GAS);
        $this->readings->create('gas', ['meter_id' => self::GAS, 'date' => '2024-12-31', 'counter' => 12000.0]);
        $this->doc('g-i2', 'invoice', ['utility' => 'gas', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'meter_number' => 'GAS-NEU',
            'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'reading_old' => '12000', 'reading_old_date' => '31.12.2024',
            'reading_new' => '13900', 'reading_new_date' => '31.12.2025', 'total_amount' => '1400,00', 'result_kind' => 'due', 'result_amount' => '80,00']);
        $d = $this->build();
        self::assertSame([], self::items($d, 'meter', 'gas'));
        self::assertContains('meter_choice', array_column(array_filter($d['missing'], fn($x) => $x['utility'] === 'gas'), 'kind'));
    }

    public function testAnotherMeterWithItsOwnNumberAndLocationIsCreated(): void
    {
        // eigene Nummer und andere Marktlokation als jeder Zähler mit Daten: ein anderer Zähler (etwa die Wärmepumpe)
        $this->household();
        $this->doc('s-wp', 'invoice', ['utility' => 'strom', 'supplier' => 'Südlicht Strom GmbH', 'kind' => 'annual', 'meter_number' => 'STR-WP',
            'market_location' => DraftFixture::MALO_A, 'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'reading_old' => '100',
            'reading_old_date' => '31.12.2024', 'reading_new' => '2100', 'reading_new_date' => '31.12.2025', 'total_amount' => '500,00',
            'result_kind' => 'credit', 'result_amount' => '20,00']);
        $m = self::items($this->build(), 'meter', 'strom');
        self::assertSame([['create', 'STR-WP']], array_map(fn($x) => [$x['action'], self::field($x, 'serial')['value'] ?? null], $m));
    }

    public function testANearStartOfAnotherSupplierIsNotTheRecordedContract(): void
    {
        // zwei Tage nach dem Beginn im Bestand, aber ein anderer Versorger: kein „derselbe Vertrag“
        $this->household();
        $this->doc('g-c3', 'contract_confirmation', ['utility' => 'gas', 'supplier' => 'Südlicht Gas AG', 'supply_start' => '03.01.2025', 'tariff_name' => 'Süd 1',
            'working_price' => '11,00', 'base_price' => '14,00', 'base_price_period' => 'month', 'advance' => '120,00', 'meter_number' => 'GAS-77']);
        $d = $this->build();
        $c = self::items($d, 'contract', 'gas');
        self::assertSame([[null, 'overlap', false]], array_map(fn($x) => [$x['contract_id'], $x['status'], $x['checked']], $c), 'neu, als Frage');
        self::assertNotContains('start_near', array_column($d['findings'], 'kind'));
    }

    public function testInvoicesOverSeveralYearsBelongToTheContractOfTheirTime(): void
    {
        // Tarifwechsel beim selben Versorger im Bestand, nur Rechnungen im Posteingang
        $mk = fn(string $start, ?string $end) => $this->contracts->create('gas', ['meter_id' => self::GAS, 'provider' => 'Westlicht Gas GmbH', 'start' => $start,
            'end' => $end, 'auto_renews' => false, 'working_prices' => [['from' => $start, 'ct_per_kwh' => 9.8]]]);
        $mk('2023-01-01', '2023-12-31');
        $a = $mk('2024-01-01', '2024-12-31');
        $b = $mk('2025-01-01', null);
        $this->readings->create('gas', ['meter_id' => self::GAS, 'date' => '2025-01-01', 'counter' => 12000.0]);
        $base = ['utility' => 'gas', 'supplier' => 'Westlicht Gas GmbH', 'kind' => 'annual', 'meter_number' => 'GAS-77', 'result_kind' => 'due', 'result_amount' => '10,00'];
        $this->doc('g-i1', 'invoice', $base + ['period_from' => '01.01.2024', 'period_to' => '31.12.2024', 'reading_old' => '10000', 'reading_old_date' => '31.12.2023',
            'reading_new' => '12000', 'reading_new_date' => '31.12.2024', 'total_amount' => '1300,00']);
        $this->doc('g-i2', 'invoice', $base + ['period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'reading_old' => '12000', 'reading_old_date' => '31.12.2024',
            'reading_new' => '13900', 'reading_new_date' => '31.12.2025', 'total_amount' => '1400,00', 'advance_new' => '120,00', 'advance_new_from' => '01.02.2026',
            'working_prices' => [['from' => '01.07.2025', 'ct' => '11,00', 'basis' => 'gross', 'vat_pct' => '19 %']]]);
        $d = $this->build();
        $c = array_column(self::items($d, 'contract', 'gas'), 'contract_id', 'contract_key');
        self::assertSame([(string)$a['id'], (string)$b['id']], array_values($c), 'zwei vorhandene Verträge, der von 2023 ohne Dokument erscheint nicht');
        $bills = array_column(self::items($d, 'bill', 'gas'), 'contract_key', 'key');
        self::assertSame([(string)$a['id'], (string)$b['id']], array_values(array_map(fn($k) => $c[$k] ?? null, $bills)), 'je Rechnung der Vertrag ihrer Zeit');
        $r = self::items($d, 'readings', 'gas')[0];
        self::assertSame('exists', self::field($r, 'r:2024-12-31')['status'], 'derselbe Stand einen Tag daneben im Bestand');
        $ap = self::items($d, 'advances', 'gas')[0];
        self::assertSame(['update', false], [$ap['status'], $ap['checked']], 'neuer Abschlag am vorhandenen Vertrag: eine Frage');
        $wp = self::items($d, 'prices', 'gas')[0];
        self::assertSame(['update', false, 'new'], [$wp['status'], $wp['checked'], self::field($wp, 'wp:2025-07-01')['status'] ?? null], 'neue Preiszeile am vorhandenen Vertrag: eine Frage');
    }

    public function testTheNumberGoesToTheDeviceThatRanAtTheTime(): void
    {
        // zwei Geräte ohne Nummer im Bestand (Tausch Mitte 2024), die Rechnung von 2025 nennt die Nummer des zweiten
        $this->setMeterDevices('gas', [
            ['id' => 'd1', 'serial' => null, 'installed_on' => '2020-01-01', 'initial_counter' => 0.0, 'removed_on' => '2024-06-30', 'final_counter' => 9000.0, 'reason' => null],
            ['id' => 'd2', 'serial' => null, 'installed_on' => '2024-06-30', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null],
        ], self::GAS);
        $this->readings->create('gas', ['meter_id' => self::GAS, 'date' => '2024-12-31', 'counter' => 1500.0]);
        $this->doc('g-i2', 'invoice', ['utility' => 'gas', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'meter_number' => 'GAS-NEU',
            'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'reading_old' => '1500', 'reading_old_date' => '31.12.2024',
            'reading_new' => '3400', 'reading_new_date' => '31.12.2025', 'total_amount' => '1400,00', 'result_kind' => 'due', 'result_amount' => '80,00']);
        $d = $this->build();
        $m = self::items($d, 'meter', 'gas')[0];
        self::assertSame(['existing', 'd2'], [$m['action'], self::field($m, 'serial')['device_id'] ?? null]);
        $res = $this->draft->apply([$m['id']], [], [], null, self::TODAY);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        self::assertSame([null, 'GAS-NEU'], array_column($this->meters->get('gas', self::GAS)['devices'], 'serial'));
    }

    public function testAGroupContractIsTheRecordedContract(): void
    {
        // der Stromzähler gehört zu einer Gruppe, der Vertrag hängt an der Gruppe
        $this->meters->update('strom', self::STROM, ['malo_id' => DraftFixture::MALO_B]);
        $this->readings->create('strom', ['meter_id' => self::STROM, 'date' => '2025-04-01', 'counter' => 2000.0]);
        $gid = (string)$this->meters->createGroup('strom', ['name' => 'Haus'])['id'];
        $this->meters->update('strom', self::STROM, ['meter_group_id' => $gid]);
        $g = $this->contracts->create('strom', ['meter_group_id' => $gid, 'provider' => 'Nordlicht Energie GmbH', 'start' => '2025-04-01',
            'working_prices' => [['from' => '2025-04-01', 'ct_per_kwh' => 31.2]]]);
        $this->doc('s-c1', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '01.04.2025',
            'tariff_name' => 'Nordlicht Basis 12', 'working_price' => '31,20', 'base_price' => '12,90', 'base_price_period' => 'month',
            'market_location' => DraftFixture::MALO_B, 'contract_number' => 'NL-4401']);
        $c = self::items($this->build(), 'contract', 'strom');
        self::assertSame([[(string)$g['id'], 'update']], array_map(fn($x) => [$x['contract_id'], $x['status']], $c), 'der Gruppenvertrag, kein zweiter');
    }

    public function testSeveralCandidatesAreAQuestionUntilADocumentIsAssigned(): void
    {
        // zwei Gaszähler mit Daten, beide ohne Nummer; die Rechnung nennt eine unbekannte Nummer
        $this->readings->create('gas', ['meter_id' => self::GAS, 'date' => '2024-12-31', 'counter' => 12000.0]);
        $second = $this->meters->create('gas', ['name' => 'Gaszähler Garage']);
        $this->readings->create('gas', ['meter_id' => (string)$second['id'], 'date' => '2024-12-31', 'counter' => 500.0]);
        $id = $this->doc('g-i2', 'invoice', ['utility' => 'gas', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'meter_number' => 'GAS-88',
            'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'reading_old' => '500', 'reading_old_date' => '31.12.2024',
            'reading_new' => '900', 'reading_new_date' => '31.12.2025', 'total_amount' => '300,00', 'result_kind' => 'due', 'result_amount' => '5,00']);
        $d = $this->build();
        self::assertSame([], self::items($d, 'meter', 'gas'), 'kein neuer Zähler');
        self::assertSame([], self::items($d, 'readings', 'gas'));
        self::assertSame([], self::items($d, 'bill', 'gas'));
        $q = array_values(array_filter($d['missing'], fn($x) => $x['kind'] === 'meter_choice'));
        self::assertSame(['GAS-88', 1], [$q[0]['params']['meter'] ?? null, $q[0]['params']['count'] ?? null]);
        self::assertStringContainsString('Gaszähler Garage', $q[0]['params']['meters']);

        // im Posteingang zugeordnet: dieser Zähler, mit Nummer zum Nachtragen
        $this->inbox->update($id, ['assignment' => ['utility' => 'gas', 'meter_id' => (string)$second['id'], 'contract_id' => null]]);
        $d = $this->build();
        $m = self::items($d, 'meter', 'gas');
        self::assertSame([(string)$second['id'], 'GAS-88'], [$m[0]['meter_id'] ?? null, self::field($m[0], 'serial')['value'] ?? null]);
        self::assertSame([], array_filter($d['missing'], fn($x) => $x['kind'] === 'meter_choice'));
        self::assertSame(['r:2025-12-31'], array_column(array_filter(self::items($d, 'readings', 'gas')[0]['fields'], fn($f) => $f['status'] !== 'exists'), 'name'),
            'der Stand am 31.12.2024 steht schon da');
    }
}
