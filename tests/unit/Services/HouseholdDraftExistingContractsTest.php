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
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\DraftFixture;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.9.0 (entwurf-bestand) — Verträge im Entwurf auf einem bestehenden
 * Haushalt (Probe auf einer Kopie echter Daten, hier nur erfunden):
 *
 *   B1 Derselbe Vertrag stand als „neu, überschneidet den Bestand“ da. Der
 *      Kandidat trug beim Abgleich den Beginn der zuerst abgelegten Bestätigung
 *      derselben Vertragsnummer (einer späteren Tarifbestätigung); erst danach
 *      zog ihn die erste Rechnung auf den Beginn des Bestands vor. Die Marke
 *      steht in den Bestätigungen, das abrechnende Stadtwerk in den Rechnungen.
 *   B2 Preise, Abschläge und Rechnungen eines nicht vorbelegten Vertrags waren
 *      vorbelegt; übernommen scheiterten die Preise, eine Rechnung entstand ohne
 *      Vertrag und buchte ihr Ergebnis auf den Vertrag, der damals lief.
 *
 * Der Bestand führt den Stromzähler ohne Nummer und die Verträge ohne
 * Vertragsnummer. Anbieter, Nummern und Beträge sind ausgedacht.
 */
#[CoversClass(HouseholdDraft::class)]
#[CoversClass(DraftApplier::class)]
#[CoversClass(InboxDraftService::class)]
final class HouseholdDraftExistingContractsTest extends ServiceTestCase
{
    private const TODAY = '2026-10-26';
    private const STROM = 'm_strom_default';
    private const BRAND = 'MUSTERSTROM';
    private const UTILITY = 'Stadtwerke Musterstadt GmbH';

    private InboxService $inbox;
    private InboxDraftService $draft;
    private DraftApplier $applier;
    private BillService $bills;
    /** @var array<string,string> Name → Vertrags-ID im Bestand */
    private array $stock = [];

    protected function setUp(): void
    {
        parent::setUp();
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings);
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, InboxFixture::provider()), $proposals,
            new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $this->applier = new DraftApplier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n);
        $this->draft = new InboxDraftService($this->inbox,
            new HouseholdDraft($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n, $proposals),
            $this->applier);
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
    private static function items(array $d, string $kind): array
    {
        return array_values(array_filter($d['items'], fn($i) => $i['kind'] === $kind && $i['utility'] === 'strom'));
    }

    private static function field(array $item, string $name): ?array
    {
        foreach ($item['fields'] as $f) if ($f['name'] === $name) return $f;
        return null;
    }

    /**
     * Der Bestand: Stromzähler ohne Nummer mit Ablesungen, drei Verträge ohne
     * Vertragsnummer — der Vorversorger, die Marke bis Ende November 2025, die
     * Marke wieder ab Dezember 2025.
     */
    private function household(): void
    {
        foreach ([['2021-09-25', 1000.0], ['2022-12-31', 4000.0], ['2023-12-31', 7000.0], ['2024-12-31', 10000.0], ['2025-11-30', 12800.0]] as [$date, $counter]) {
            $this->readings->create('strom', ['meter_id' => self::STROM, 'date' => $date, 'counter' => $counter]);
        }
        $mk = fn(array $c) => (string)$this->contracts->create('strom', $c + ['meter_id' => self::STROM])['id'];
        $this->stock['alt'] = $mk(['provider' => 'Altlicht Strom', 'start' => '2019-01-01', 'end' => '2021-09-24', 'auto_renews' => false,
            'working_prices' => [['from' => '2019-01-01', 'ct_per_kwh' => 28.0]]]);
        $this->stock['brand'] = $mk(['provider' => 'Musterstrom', 'start' => '2021-09-25', 'end' => '2025-11-30', 'auto_renews' => false,
            'working_prices' => [['from' => '2021-09-25', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2021-09-25', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2021-09-25', 'amount_eur' => 70.0]]]);
        $this->stock['next'] = $mk(['provider' => 'Musterstrom', 'start' => '2025-12-01', 'auto_renews' => true,
            'working_prices' => [['from' => '2025-12-01', 'ct_per_kwh' => 33.0]], 'base_prices' => [['from' => '2025-12-01', 'eur_per_month' => 11.0]]]);
    }

    /** Bestätigung der Marke: der Vertrag ab 25.09.2021 oder die Tarifbestätigung ab 01.03.2023 (dieselbe Vertragsnummer). */
    private function confirmation(string $which, array $extra = []): string
    {
        return $which === 'start'
            ? $this->doc('conf-2021', 'contract_confirmation', $extra + ['utility' => 'strom', 'supplier' => self::BRAND, 'supply_start' => '25.09.2021',
                'tariff_name' => 'Muster Klassik', 'working_price' => '30,00', 'base_price' => '10,00', 'base_price_period' => 'month', 'advance' => '70,00',
                'meter_number' => 'STR-55', 'contract_number' => 'MS-1'])
            : $this->doc('conf-2023', 'contract_confirmation', $extra + ['utility' => 'strom', 'supplier' => self::BRAND, 'supply_start' => '01.03.2023',
                'tariff_name' => 'Muster Fix', 'working_price' => '32,00', 'base_price' => '10,00', 'base_price_period' => 'month', 'advance' => '75,00',
                'meter_number' => 'STR-55', 'contract_number' => 'MS-1']);
    }

    /** Rechnungen des abrechnenden Stadtwerks unter seinem Firmennamen, die erste ab dem Beginn des Vertrags. */
    private function invoices(array $extra = ['contract_number' => 'MS-1'], bool $first = true): void
    {
        $periods = [['25.09.2021', '31.12.2021', '1000', '2000'], ['01.01.2022', '31.12.2022', '2000', '4000'], ['01.01.2023', '31.12.2023', '4000', '7000'],
            ['01.01.2024', '31.12.2024', '7000', '10000'], ['01.01.2025', '30.11.2025', '10000', '12800']];
        foreach ($first ? $periods : array_slice($periods, 1, null, true) as $i => [$from, $to, $old, $new]) {
            $this->doc("inv-$i", 'invoice', $extra + ['utility' => 'strom', 'supplier' => self::UTILITY, 'kind' => 'annual', 'meter_number' => 'STR-55',
                'period_from' => $from, 'period_to' => $to, 'reading_old' => $old, 'reading_new' => $new, 'reading_new_date' => $to,
                'total_amount' => '900,00', 'advances_total' => '840,00', 'result_kind' => 'due', 'result_amount' => '60,00']);
        }
    }

    /** @return array<string,string> */
    public static function filingOrders(): array
    {
        return ['die Tarifbestätigung zuerst abgelegt' => ['later'], 'die Vertragsbestätigung zuerst abgelegt' => ['start']];
    }

    /**
     * B1 — zwei Bestätigungen desselben Vertrags (gleiche Nummer), dazu die
     * Rechnungen des Stadtwerks: der vorhandene Vertrag der Marke, gleich in
     * welcher Reihenfolge abgelegt. Bis v3.8 trug der Kandidat beim Abgleich den
     * Beginn der zuerst abgelegten (01.03.2023), überschnitt so den Bestand und
     * rückte erst danach auf die erste Rechnung (25.09.2021) vor.
     */
    #[DataProvider('filingOrders')]
    public function testTwoConfirmationsOfOneContractAreTheRecordedContractWhicheverWasFiledFirst(string $first): void
    {
        $this->household();
        $this->confirmation($first);
        $this->confirmation($first === 'later' ? 'start' : 'later');
        $this->invoices();
        $d = $this->build();

        $c = self::items($d, 'contract');
        self::assertSame([[$this->stock['brand'], 'update', '2021-09-25', self::BRAND]],
            array_map(fn($x) => [$x['contract_id'], $x['status'], $x['params']['from'], $x['params']['provider']], $c), 'derselbe Vertrag, kein neuer mit Überschneidung');
        self::assertSame([], array_values(array_filter($d['items'], fn($i) => $i['status'] === 'overlap')));
        self::assertSame(['2021-09-25', 'exists'], [self::field($c[0], 'start')['value'], self::field($c[0], 'start')['status']], 'der Beginn des Bestands');

        // die Tarifbestätigung bringt eine neue Preiszeile ab ihrem Beginn, die alte steht schon da
        $p = self::items($d, 'prices')[0];
        self::assertSame(['exists', 'new', 32.0], [self::field($p, 'wp:2021-09-25')['status'] ?? null, self::field($p, 'wp:2023-03-01')['status'] ?? null,
            (float)(self::field($p, 'wp:2023-03-01')['value'] ?? 0)]);
        self::assertSame(['update', false, null], [$p['status'], $p['checked'], $p['requires']], 'am vorhandenen Vertrag: eine Frage für sich');

        // die Rechnungen gehören an ihn, sie sind neu und berühren nichts Vorhandenes
        $bills = self::items($d, 'bill');
        self::assertCount(5, $bills);
        foreach ($bills as $b) self::assertSame([$c[0]['contract_key'], 'new', true, null], [$b['contract_key'], $b['status'], $b['checked'], $b['requires']]);

        // übernommen: kein neuer Vertrag, die Rechnungen am Vertrag der Marke
        $before = count($this->contracts->list('strom'));
        $res = $this->draft->apply(array_column(array_filter($d['items'], fn($i) => $i['actionable']), 'id'), [], [], null, self::TODAY);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        self::assertSame([0, $before], [$res['created']['contracts'], count($this->contracts->list('strom'))]);
        self::assertSame(array_fill(0, 5, $this->stock['brand']), array_column($this->bills->list('strom'), 'contract_id'));
        self::assertSame([['2021-09-25', 30.0], ['2023-03-01', 32.0]], array_map(fn($r) => [$r['from'], (float)$r['ct_per_kwh']],
            $this->contracts->get('strom', $this->stock['brand'])['working_prices']), 'die neue Preiszeile am Vertrag der Marke');
    }

    /**
     * B1 — der früheste Beginn der Bestätigungen gilt, auch ohne Rechnung ab
     * dem Beginn (die Rechnungen beginnen 2022): Bis v3.8 blieb es beim Beginn
     * der zuerst abgelegten Tarifbestätigung, vorgezogen nur bis zur ersten
     * Rechnung, und der Vertrag überschnitt den Bestand.
     */
    public function testTheEarliestConfirmationGivesTheStartWhicheverWasFiledFirst(): void
    {
        $this->household();
        $this->confirmation('later');
        $this->confirmation('start');
        $this->invoices(['contract_number' => 'MS-1'], false);
        $c = self::items($this->build(), 'contract');
        self::assertSame([[$this->stock['brand'], 'update', '2021-09-25']], array_map(fn($x) => [$x['contract_id'], $x['status'], $x['params']['from']], $c));
    }

    /**
     * B1 — nur die spätere Tarifbestätigung: Die erste Rechnung zieht den
     * Beginn auf den des Bestands vor, und mit diesem Beginn wird noch einmal
     * abgeglichen: der vorhandene Vertrag, keine Überschneidung.
     */
    public function testAStartPulledBackToTheFirstBillIsComparedWithTheRecordedContractAgain(): void
    {
        $this->household();
        $this->confirmation('later');
        $this->invoices();
        $d = $this->build();
        $c = self::items($d, 'contract');
        self::assertSame([[$this->stock['brand'], 'update', '2021-09-25']], array_map(fn($x) => [$x['contract_id'], $x['status'], $x['params']['from']], $c));
        self::assertSame(['2021-09-25', '2023-03-01'], [array_values(array_filter($d['findings'], fn($f) => $f['kind'] === 'start_from_invoice'))[0]['params']['from'] ?? null,
            array_values(array_filter($d['findings'], fn($f) => $f['kind'] === 'start_from_invoice'))[0]['params']['to'] ?? null], 'der Grund steht dabei');
    }

    /**
     * Bestätigung der Marke und Lieferbeginn-Brief des Stadtwerks zwei Tage
     * später (gleiche Kundennummer, gleicher Zähler): derselbe Vertrag — wie
     * gegen den Bestand (bis 7 Tage). Bis v3.8 wurde der Brief ein zweiter
     * Vertrag, und die Rechnungen gingen an ihn.
     */
    public function testALetterOfTheBillingUtilityAFewDaysAfterTheBrandIsTheSameContract(): void
    {
        $this->household();
        $this->confirmation('start', ['customer_number' => 'K-1', 'contract_number' => '']);
        $this->doc('letter', 'supply_start', ['utility' => 'strom', 'supplier' => self::UTILITY, 'supply_start' => '27.09.2021', 'meter_number' => 'STR-55', 'customer_number' => 'K-1']);
        $this->invoices(['customer_number' => 'K-1']);
        $d = $this->build();
        $c = self::items($d, 'contract');
        self::assertSame([[$this->stock['brand'], 'update']], array_map(fn($x) => [$x['contract_id'], $x['status']], $c), 'ein Vertrag, der vorhandene');
        self::assertSame(array_fill(0, 5, $c[0]['contract_key']), array_column(self::items($d, 'bill'), 'contract_key'));
    }

    /** Dasselbe auf einem leeren Haushalt: ein Vertrag ab dem Beginn der Bestätigung, nicht zwei. */
    public function testALetterAFewDaysAfterTheConfirmationCreatesNoSecondContract(): void
    {
        $this->confirmation('start', ['customer_number' => 'K-1', 'contract_number' => '']);
        $this->doc('letter', 'supply_start', ['utility' => 'strom', 'supplier' => self::UTILITY, 'supply_start' => '27.09.2021', 'meter_number' => 'STR-55', 'customer_number' => 'K-1']);
        $c = self::items($this->build(), 'contract');
        self::assertSame([['2021-09-25', 'new', null]], array_map(fn($x) => [$x['params']['from'], $x['status'], $x['contract_id']], $c));
    }

    /** Zwei Vertragsnummern wenige Tage auseinander bleiben zwei Verträge (die Woche verbindet nur einen Vertrag). */
    public function testTwoContractNumbersAFewDaysApartStayTwoContracts(): void
    {
        $base = ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'meter_number' => 'STR-1', 'working_price' => '31,20', 'base_price' => '12,90', 'base_price_period' => 'month'];
        $this->doc('c-a', 'contract_confirmation', $base + ['supply_start' => '01.04.2025', 'tariff_name' => 'Basis', 'contract_number' => 'NL-1']);
        $this->doc('c-b', 'contract_confirmation', $base + ['supply_start' => '04.04.2025', 'tariff_name' => 'Wärme', 'contract_number' => 'NL-2']);
        self::assertSame(['2025-04-01', '2025-04-04'], array_map(fn($x) => $x['params']['from'], self::items($this->build(), 'contract')));
    }

    /** Wenige Tage auseinander an zwei Zählern (Haus und Wärmepumpe): zwei Verträge. */
    public function testNearStartsAtTwoMetersStayTwoContracts(): void
    {
        $base = ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'working_price' => '31,20', 'base_price' => '12,90', 'base_price_period' => 'month'];
        $this->doc('c-a', 'contract_confirmation', $base + ['supply_start' => '01.04.2025', 'tariff_name' => 'Basis', 'meter_number' => 'STR-1']);
        $this->doc('c-b', 'contract_confirmation', $base + ['supply_start' => '03.04.2025', 'tariff_name' => 'Wärme', 'meter_number' => 'STR-2']);
        $c = self::items($this->build(), 'contract');
        self::assertSame(['2025-04-01', '2025-04-03'], array_map(fn($x) => $x['params']['from'], $c));
        self::assertNotSame($c[0]['meter_key'], $c[1]['meter_key']);
    }

    /**
     * Ein Vorvertrag aus Rechnungen mit einer anderen Vertragsnummer
     * (invoicesBeforeStart) wird ebenfalls mit dem Bestand abgeglichen: Er
     * überschneidet den Vertrag der Marke und ist eine Frage. Bis v3.8 stand er
     * als neu und vorbelegt da.
     */
    public function testAPredecessorFromBillsIsComparedWithTheRecordedContractsToo(): void
    {
        $this->household();
        $sued = ['utility' => 'strom', 'supplier' => 'Südlicht Strom GmbH', 'meter_number' => 'STR-55', 'customer_number' => 'SL-1'];
        $this->doc('sued-conf', 'contract_confirmation', $sued + ['supply_start' => '01.06.2022', 'tariff_name' => 'Süd 1', 'contract_number' => 'SL-100',
            'working_price' => '29,00', 'base_price' => '9,00', 'base_price_period' => 'month']);
        $this->doc('sued-inv', 'invoice', $sued + ['contract_number' => 'SL-099', 'kind' => 'annual', 'period_from' => '01.01.2022', 'period_to' => '31.05.2022',
            'reading_old' => '4000', 'reading_new' => '5100', 'reading_new_date' => '31.05.2022', 'total_amount' => '400,00', 'advances_total' => '350,00',
            'result_kind' => 'due', 'result_amount' => '50,00']);
        $c = array_map(fn($x) => [$x['params']['from'], $x['status'], $x['checked']], self::items($this->build(), 'contract'));
        sort($c);
        self::assertSame([['2022-01-01', 'overlap', false], ['2022-06-01', 'overlap', false]], $c);
    }

    /**
     * B2 — gleicher Beginn wie ein Vertrag im Bestand, anderer Name (Marke im
     * Bestand, Stadtwerk im Dokument), keine Nummer im Bestand: die Rückfrage
     * „derselbe Versorger?“ mit beiden Namen, nicht vorbelegt, ihre Rechnung nur
     * mit ihr. Angehakt ergänzt sie den vorhandenen Vertrag; bis v3.8 legte der
     * angehakte Widerspruch einen zweiten Vertrag mit demselben Beginn an.
     */
    public function testTheSameStartUnderAnotherNameAsksWhetherItIsTheSameSupplier(): void
    {
        $this->household();
        $this->doc('conf-2025', 'contract_confirmation', ['utility' => 'strom', 'supplier' => self::UTILITY, 'supply_start' => '01.12.2025', 'tariff_name' => 'Musterstadt Fix 24',
            'working_price' => '33,00', 'base_price' => '11,00', 'base_price_period' => 'month', 'advance' => '80,00', 'meter_number' => 'STR-55']);
        $this->doc('inv-2026', 'invoice', ['utility' => 'strom', 'supplier' => self::UTILITY, 'kind' => 'annual', 'meter_number' => 'STR-55',
            'period_from' => '01.12.2025', 'period_to' => '30.06.2026', 'reading_old' => '12800', 'reading_new' => '14600', 'reading_new_date' => '30.06.2026',
            'total_amount' => '700,00', 'advances_total' => '560,00', 'result_kind' => 'due', 'result_amount' => '140,00']);
        $d = $this->build();
        $c = self::items($d, 'contract')[0];
        self::assertSame([$this->stock['next'], 'update', 'inboxDraft.item.contract.sameSupplier', 'Musterstrom', self::UTILITY, false],
            [$c['contract_id'], $c['status'], $c['label_key'], $c['params']['existing'] ?? null, $c['params']['provider'], $c['checked']], 'eine Rückfrage, kein Widerspruch');
        self::assertContains('same_supplier', $c['reasons']);
        $prov = self::field($c, 'provider');
        self::assertSame(['conflict', 'Musterstrom', self::UTILITY, false], [$prov['status'], $prov['existing'] ?? null, $prov['value'], $prov['check']], 'beide Namen am Feld');

        $bill = self::items($d, 'bill')[0];
        self::assertSame([$c['id'], false, true], [$bill['requires'], $bill['checked'], $bill['checked_with']], 'die Rechnung geht nur mit dem Vertrag');
        $adv = self::items($d, 'advances')[0];
        self::assertSame([$c['id'], false], [$adv['requires'], $adv['checked']]);

        // allein abgelehnt, bevor etwas geschrieben wird
        try {
            $this->draft->apply([$bill['id']], [], [], null, self::TODAY);
            self::fail('die Rechnung ohne ihren Vertrag ist abgelehnt');
        } catch (LocalizedException $e) {
            self::assertSame('errors.inboxDraft.needs.contract', $e->key);
        }
        self::assertSame([], $this->bills->list('strom'));

        // mit dem Vertrag: derselbe Versorger — der vorhandene Vertrag, der Name im Bestand bleibt
        $before = count($this->contracts->list('strom'));
        $res = $this->draft->apply([$c['id'], $bill['id'], $adv['id']], [], [], null, self::TODAY);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        self::assertSame($before, count($this->contracts->list('strom')), 'kein zweiter Vertrag mit demselben Beginn');
        $next = $this->contracts->get('strom', $this->stock['next']);
        self::assertSame(['Musterstrom', 80.0], [$next['provider'], (float)($next['advance_payments'][0]['amount_eur'] ?? 0)]);
        self::assertSame([$this->stock['next']], array_column($this->bills->list('strom'), 'contract_id'));
    }

    /** Eine andere Vertragsnummer im Bestand am selben Beginn bleibt ein Widerspruch (kein „derselbe Versorger?“). */
    public function testAnotherContractNumberAtTheSameStartStaysAContradiction(): void
    {
        $this->household();
        $this->contracts->update('strom', $this->stock['next'], ['contract_number' => 'MS-9']);
        $this->doc('conf-2025', 'contract_confirmation', ['utility' => 'strom', 'supplier' => self::UTILITY, 'supply_start' => '01.12.2025', 'tariff_name' => 'Musterstadt Fix 24',
            'working_price' => '33,00', 'base_price' => '11,00', 'base_price_period' => 'month', 'meter_number' => 'STR-55', 'contract_number' => 'SM-77']);
        $c = self::items($this->build(), 'contract')[0];
        self::assertSame([null, 'conflict', 'inboxDraft.item.contract.new'], [$c['contract_id'], $c['status'], $c['label_key']]);
    }

    /**
     * B2 — ein neuer Vertrag, der den Bestand überschneidet (anderer Versorger
     * mitten in der Laufzeit), ist nicht vorbelegt — und damit auch nicht seine
     * Preise, Abschläge und Rechnungen. Allein ausgewählt: abgelehnt, bevor
     * etwas geschrieben wird; mit dem Vertrag gehen sie an ihn.
     */
    public function testEntriesOfAContractThatIsNotPreselectedAreNotPreselectedEither(): void
    {
        $this->household();
        $this->doc('sued-conf', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südlicht Strom GmbH', 'supply_start' => '01.03.2024', 'tariff_name' => 'Süd 1',
            'working_price' => '29,00', 'base_price' => '9,00', 'base_price_period' => 'month', 'advance' => '65,00', 'meter_number' => 'STR-55']);
        $this->doc('sued-inv', 'invoice', ['utility' => 'strom', 'supplier' => 'Südlicht Strom GmbH', 'kind' => 'annual', 'meter_number' => 'STR-55',
            'period_from' => '01.03.2024', 'period_to' => '28.02.2025', 'reading_old' => '7500', 'reading_new' => '10500', 'reading_new_date' => '28.02.2025',
            'total_amount' => '980,00', 'advances_total' => '780,00', 'result_kind' => 'due', 'result_amount' => '200,00']);
        $d = $this->build();
        $c = self::items($d, 'contract')[0];
        self::assertSame([null, 'overlap', false], [$c['contract_id'], $c['status'], $c['checked']]);
        $children = [...self::items($d, 'prices'), ...self::items($d, 'advances'), ...self::items($d, 'bill')];
        self::assertCount(3, $children);
        foreach ($children as $x) {
            self::assertSame(['new', false, true, $c['id']], [$x['status'], $x['checked'], $x['checked_with'] ?? null, $x['requires']], "{$x['kind']}: nur mit dem Vertrag");
        }

        $contracts = $this->contracts->list('strom');
        foreach ($children as $x) {
            try {
                $this->draft->apply([$x['id']], [], [], null, self::TODAY);
                self::fail("{$x['kind']} ohne den Vertrag ist abgelehnt");
            } catch (LocalizedException $e) {
                self::assertSame('errors.inboxDraft.needs.contract', $e->key);
            }
        }
        self::assertSame($contracts, $this->contracts->list('strom'), 'nichts geschrieben');
        self::assertSame([], $this->bills->list('strom'));

        $res = $this->draft->apply([$c['id'], ...array_column($children, 'id')], [], [], null, self::TODAY);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        $new = (string)array_values(array_filter($res['applied'], fn($a) => $a['kind'] === 'contract'))[0]['ref']['id'];
        self::assertSame('Südlicht Strom GmbH', $this->contracts->get('strom', $new)['provider']);
        self::assertSame([['2024-03-01', 29.0]], array_map(fn($r) => [$r['from'], (float)$r['ct_per_kwh']], $this->contracts->get('strom', $new)['working_prices']));
        self::assertSame([$new], array_column($this->bills->list('strom'), 'contract_id'), 'die Rechnung am neuen Vertrag');
    }

    /**
     * Scheitert der Vertrag einer Rechnung (oder fehlt er), entsteht die
     * Rechnung nicht: Bis v3.8 stand sie ohne Vertrag da und buchte ihr Ergebnis
     * auf den Vertrag, der damals lief — hier den der Marke.
     */
    public function testABillWithoutItsContractIsNotBookedOnTheContractThatRanAtTheTime(): void
    {
        $this->household();
        $this->doc('sued-conf', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südlicht Strom GmbH', 'supply_start' => '01.03.2024', 'tariff_name' => 'Süd 1',
            'working_price' => '29,00', 'base_price' => '9,00', 'base_price_period' => 'month', 'meter_number' => 'STR-55']);
        $this->doc('sued-inv', 'invoice', ['utility' => 'strom', 'supplier' => 'Südlicht Strom GmbH', 'kind' => 'annual', 'meter_number' => 'STR-55',
            'period_from' => '01.03.2024', 'period_to' => '28.02.2025', 'reading_old' => '7500', 'reading_new' => '10500', 'reading_new_date' => '28.02.2025',
            'total_amount' => '980,00', 'advances_total' => '780,00', 'result_kind' => 'due', 'result_amount' => '200,00']);
        $d = $this->build();
        $bill = self::items($d, 'bill')[0];
        // wie nach einem gescheiterten Vertrag: die Rechnung kommt ohne ihn beim Übernehmen an
        $res = $this->applier->apply($d, [$bill], [], []);
        self::assertSame([], $res['applied']);
        self::assertSame('errors.inboxDraft.needs.contract', $res['failed'][0]['code'] ?? null);
        self::assertSame([], $this->bills->list('strom'), 'keine Rechnung ohne Vertrag');
        self::assertSame([], (array)($this->contracts->get('strom', $this->stock['brand'])['special_payments'] ?? []), 'nichts auf den Vertrag der Marke gebucht');
    }
}
