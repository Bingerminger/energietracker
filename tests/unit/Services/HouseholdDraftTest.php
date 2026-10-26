<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\DossierService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\DraftApplier;
use Energietracker\Services\Inbox\Gist;
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

/**
 * v3.7.0 (Haushalt aus Dokumenten) — der Entwurf über alle ausgelesenen
 * Dokumente, mit erfundenen Auslesungen (keine echten Anbieter, Nummern,
 * Beträge): Lieferbeginn füllt die Bestätigung, zwei Schreibweisen eines
 * Versorgers, Zählertausch über die Marktlokation, Dokument ohne Zählernummer
 * über die Vertragsnummer, Endstand = Anfangsstand, Lücke zwischen Verträgen,
 * inhaltsgleiche Dokumente, Standardzähler übernommen, Preiskette, fallender
 * Stand, Idempotenz, Bestand wird nicht still überschrieben.
 */
#[CoversClass(HouseholdDraft::class)]
#[CoversClass(DraftApplier::class)]
#[CoversClass(InboxDraftService::class)]
final class HouseholdDraftTest extends ServiceTestCase
{
    private const TODAY = '2026-01-15';

    private InboxService $inbox;
    private InboxDraftService $draft;
    private DossierService $dossier;
    private BillService $bills;
    private ReminderService $reminders;

    protected function setUp(): void
    {
        parent::setUp();
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $activity = new ActivityService($this->store, $this->settings, $this->i18n);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings);
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, InboxFixture::provider()),
            $proposals,
            new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n));
        // Kernaussage und Akte wie in der App (Paket betrieb): nach dem Übernehmen eines Entwurfs geprüft
        $this->inbox->attachGist(new Gist($this->meters, $this->contracts, $this->consumption, $this->bills, $this->readings));
        $this->dossier = new DossierService($this->inbox, $att, $this->bills, $this->contracts, $this->meters, $this->readings, $this->i18n);
        $this->draft = new InboxDraftService($this->inbox,
            new HouseholdDraft($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n, $proposals),
            new DraftApplier($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n));
        $this->draft->attach(new BackupService($this->store, $this->i18n), $activity);
    }

    private function doc(string $tag, string $type, array $values, array $flag = [], array $extra = []): string
    {
        return DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), $tag, $type, $values, $flag, $extra);
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

    /** Alles Vorbelegte übernehmen (wie der Knopf ohne weitere Wahl). */
    private function applyChecked(array $d, array $confirm = [], array $choose = [], array $more = []): array
    {
        $ids = [...array_column(array_filter($d['items'], fn($i) => $i['checked']), 'id'), ...$more];
        return $this->draft->apply($ids, $confirm, $choose, null, self::TODAY);
    }

    // Erfundene Dokumente eines Mieters mit Strom
    private function tenantConfirmation(string $tag = 'c1', array $more = []): string
    {
        return $this->doc($tag, 'contract_confirmation', $more + [
            'utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'tariff_name' => 'Nordlicht Basis 12',
            'working_price' => '31,20', 'base_price' => '12,90', 'base_price_period' => 'month',
            'contract_number' => 'NL-4401', 'meter_number' => 'STR-1001',
        ]);
    }

    private function tenantInvoice(string $tag, string $from, string $to, string $old, string $new, array $more = [], array $flag = []): string
    {
        return $this->doc($tag, 'invoice', $more + [
            'utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual',
            'period_from' => $from, 'period_to' => $to, 'consumption' => '1800',
            'reading_old' => $old, 'reading_old_date' => $from === '01.04.2025' ? '01.04.2025' : $from,
            'reading_new' => $new, 'reading_new_date' => $to,
            'total_amount' => '640,00', 'advances_total' => '600,00', 'result_kind' => 'due', 'result_amount' => '40,00',
            'meter_number' => 'STR-1001', 'contract_number' => 'NL-4401',
        ], $flag);
    }

    // ── Zusammenführung ─────────────────────────────────────────────────

    public function testSupplyStartLetterFillsAConfirmationWithoutStart(): void
    {
        $this->tenantConfirmation();
        $this->doc('l1', 'supply_start', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie', 'supply_start' => '01.04.2025',
            'meter_number' => 'STR-1001', 'reading_start' => '2000', 'contract_number' => 'NL-4401']);
        $d = $this->build();
        $c = self::items($d, 'contract', 'strom');
        self::assertCount(1, $c, 'Bestätigung und Lieferbeginn-Brief sind ein Vertrag');
        self::assertSame('2025-04-01', self::field($c[0], 'start')['value']);
        self::assertSame('supply_start', self::field($c[0], 'start')['basis'], 'Beginn aus dem Lieferbeginn-Brief');
        self::assertTrue($c[0]['checked'], 'ohne Zweifel vorbelegt');
        self::assertSame('Nordlicht Energie GmbH', self::field($c[0], 'provider')['value'], 'Schreibweise der Bestätigung');
        // der Abschlag ist kein Pflichtfeld — er fehlt nur
        self::assertNull(self::field($c[0], 'advance'));
        self::assertContains('advance', array_column($d['missing'], 'kind'), 'Abschlag fehlt: steht in keinem Dokument');
        $r = self::items($d, 'readings', 'strom');
        self::assertSame(2000.0, self::field($r[0], 'r:2025-04-01')['value'], 'Stand bei Lieferbeginn');
    }

    public function testALetterAloneCreatesAContract(): void
    {
        $this->doc('l1', 'supply_start', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '01.04.2025', 'meter_number' => 'STR-1001']);
        $c = self::items($this->build(), 'contract', 'strom');
        self::assertCount(1, $c);
        self::assertSame('2025-04-01', self::field($c[0], 'start')['value']);
    }

    public function testTwoSpellingsOfOneSupplierAreOneSupplyRelationship(): void
    {
        // eine Regel für den ganzen Posteingang (Proposals::supplierMatch): Groß/klein,
        // Rechtsform, zusammengeschrieben, ein Zusatz wie „Vertrieb“ — ohne Vertragsnummer
        $this->doc('c1', 'contract_confirmation', ['utility' => 'gas', 'supplier' => 'Musterhausen-Energie GmbH', 'supply_start' => '01.01.2024',
            'tariff_name' => 'Gas Klassik', 'working_price' => '9,80', 'base_price' => '120,00', 'base_price_period' => 'year', 'meter_number' => 'GAS-7']);
        $this->doc('i1', 'invoice', ['utility' => 'gas', 'supplier' => 'MUSTERHAUSENENERGIE', 'kind' => 'annual', 'period_from' => '01.01.2024',
            'period_to' => '31.12.2024', 'reading_old' => '100', 'reading_old_date' => '31.12.2023', 'reading_new' => '1300', 'reading_new_date' => '31.12.2024',
            'total_amount' => '1300,00', 'result_kind' => 'credit', 'result_amount' => '20,00', 'meter_number' => 'GAS-7']);
        $this->doc('i2', 'invoice', ['utility' => 'gas', 'supplier' => 'Musterhausen Energie Vertrieb AG', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'reading_old' => '1300', 'reading_old_date' => '31.12.2024', 'reading_new' => '2500', 'reading_new_date' => '31.12.2025',
            'total_amount' => '1250,00', 'result_kind' => 'credit', 'result_amount' => '10,00', 'meter_number' => 'GAS-7']);
        $d = $this->build();
        $c = self::items($d, 'contract', 'gas');
        self::assertCount(1, $c, 'ein Vertrag, kein zweiter aus den Rechnungen');
        $bills = self::items($d, 'bill', 'gas');
        self::assertCount(2, $bills);
        self::assertSame([$c[0]['contract_key'], $c[0]['contract_key']], array_column($bills, 'contract_key'), 'beide Rechnungen gehören zum Vertrag');
        self::assertSame('Musterhausen-Energie GmbH', self::field($c[0], 'provider')['value'], 'Schreibweise der Bestätigung');
        self::assertSame(10.0, self::field(self::items($d, 'prices', 'gas')[0], 'bp:2024-01-01')['value'], 'Grundpreis je Jahr → je Monat');
        self::assertSame(-20.0, self::field($bills[0], 'result')['value'], 'Guthaben negativ');
    }

    public function testInvoicesInAnotherSpellingAtTheSameMeterAndTimeJoinTheContract(): void
    {
        // Der Name allein reicht nicht (eine Regel für den Posteingang) — gleicher Zähler,
        // gleiche Zeit und ein gemeinsamer Name: dieselbe Lieferung, und der Entwurf sagt es
        self::assertFalse(Proposals::supplierMatch('Stadtwerke Musterhausen GmbH', 'Musterhausen Energie AG'));
        $this->doc('c1', 'contract_confirmation', ['utility' => 'gas', 'supplier' => 'Stadtwerke Musterhausen GmbH', 'supply_start' => '01.01.2024',
            'working_price' => '9,80', 'base_price' => '120,00', 'base_price_period' => 'year', 'meter_number' => 'GAS-7']);
        $this->doc('i1', 'invoice', ['utility' => 'gas', 'supplier' => 'Musterhausen Energie AG', 'kind' => 'annual', 'period_from' => '01.01.2024',
            'period_to' => '31.12.2024', 'reading_old' => '100', 'reading_old_date' => '31.12.2023', 'reading_new' => '1300', 'reading_new_date' => '31.12.2024',
            'total_amount' => '1300,00', 'result_kind' => 'credit', 'result_amount' => '20,00', 'meter_number' => 'GAS-7']);
        $d = $this->build();
        $c = self::items($d, 'contract', 'gas');
        self::assertCount(1, $c, 'kein zweiter Vertrag aus der Rechnung');
        self::assertSame($c[0]['contract_key'], self::items($d, 'bill', 'gas')[0]['contract_key']);
        $f = array_values(array_filter($d['findings'], fn($x) => $x['kind'] === 'same_supplier'));
        self::assertSame([['name' => 'Musterhausen Energie AG', 'of' => 'Stadtwerke Musterhausen GmbH']], array_column($f, 'params'));
        self::assertTrue($c[0]['checked'], 'vorbelegt: die Dokumente teilen die Lieferung');
    }

    public function testGenericWordsAloneNeverJoinTwoSuppliers(): void
    {
        // „Stadtwerke“ ist kein Name: zwei Stadtwerke sind zwei Versorger, „Stadtwerke“ allein keiner davon
        $this->doc('c1', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Stadtwerke Nordheim GmbH', 'supply_start' => '01.01.2024',
            'working_price' => '30,00', 'base_price' => '10,00', 'base_price_period' => 'month', 'meter_number' => 'STR-2001']);
        $this->doc('p1', 'price_change', ['utility' => 'strom', 'supplier' => 'Stadtwerke', 'valid_from' => '01.06.2024', 'working_price_new' => '35,00']);
        $this->doc('i1', 'invoice', ['utility' => 'strom', 'supplier' => 'Stadtwerke Südheim', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'reading_old' => '500', 'reading_old_date' => '31.12.2024', 'reading_new' => '2300', 'reading_new_date' => '31.12.2025',
            'total_amount' => '700,00', 'result_kind' => 'due', 'result_amount' => '30,00', 'meter_number' => 'STR-2001']);
        $d = $this->build();
        $c = array_column(self::items($d, 'contract', 'strom'), null, 'key');
        self::assertCount(2, $c, 'Nordheim aus der Bestätigung, Südheim aus der Rechnung');
        $byProvider = array_column(array_map(fn($x) => ['p' => self::field($x, 'provider')['value'], 'k' => $x['contract_key']], $c), 'k', 'p');
        self::assertSame($byProvider['Stadtwerke Südheim'] ?? null, self::items($d, 'bill', 'strom')[0]['contract_key'], 'die Rechnung nicht an Nordheim');
        $prices = array_values(array_filter(self::items($d, 'prices', 'strom'), fn($p) => $p['contract_key'] === ($byProvider['Stadtwerke Nordheim GmbH'] ?? '')));
        self::assertNotEmpty($prices);
        self::assertNull(self::field($prices[0], 'wp:2024-06-01'), 'die Preisänderung von „Stadtwerke“ gehört nicht zu Nordheim');
    }

    public function testMeterSwapOverTheMarketLocationIsADeviceChangeNotASecondMeter(): void
    {
        $base = ['utility' => 'gas', 'supplier' => 'Stadtwerke Musterhausen', 'kind' => 'annual', 'market_location' => DraftFixture::MALO_A,
            'total_amount' => '900,00', 'result_kind' => 'due', 'result_amount' => '10,00'];
        $this->doc('g23', 'invoice', $base + ['period_from' => '01.01.2023', 'period_to' => '31.12.2023', 'meter_number' => 'GAS-A',
            'reading_old' => '1000', 'reading_old_date' => '31.12.2022', 'reading_new' => '2200', 'reading_new_date' => '31.12.2023']);
        $this->doc('g24', 'invoice', $base + ['period_from' => '01.01.2024', 'period_to' => '31.12.2024', 'meter_number' => 'GAS-B',
            'reading_old' => '3', 'reading_old_date' => '31.12.2023', 'reading_new' => '1150', 'reading_new_date' => '31.12.2024']);
        $d = $this->build();
        $m = self::items($d, 'meter', 'gas');
        self::assertCount(1, $m, 'eine Messstelle');
        self::assertSame('adopt', $m[0]['action'], 'der leere Standardzähler wird übernommen');
        self::assertSame('GAS-A', self::field($m[0], 'serial')['value']);
        self::assertSame(DraftFixture::MALO_A, self::field($m[0], 'malo')['value']);
        $ch = self::items($d, 'meter_change', 'gas');
        self::assertCount(1, $ch, 'Nummernwechsel = Gerätewechsel');
        self::assertSame(['2023-12-31', 2200.0, 3.0, 'GAS-B'], [self::field($ch[0], 'date')['value'], self::field($ch[0], 'old_final')['value'],
            self::field($ch[0], 'new_initial')['value'], self::field($ch[0], 'serial')['value']]);
        $r = self::items($d, 'readings', 'gas')[0];
        self::assertSame(['r:2022-12-31', 'r:2024-12-31'], array_column($r['fields'], 'name'), 'am Tauschtag keine Ablesung: die Stände trägt der Tausch');

        $res = $this->applyChecked($d);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        $meters = $this->meters->list('gas');
        self::assertCount(1, $meters, 'kein zweiter Zähler');
        self::assertSame('m_gas_default', $meters[0]['id']);
        self::assertSame(['GAS-A', 'GAS-B'], array_column($meters[0]['devices'], 'serial'));
        self::assertSame(2200.0, (float)$meters[0]['devices'][0]['final_counter']);
        self::assertSame(DraftFixture::MALO_A, $meters[0]['malo_id']);
        self::assertCount(2, $this->readings->list('gas', 'm_gas_default'), 'der Anfangsstand des neuen Geräts steht am Gerät, nicht als Ablesung');
        self::assertSame(3.0, (float)$meters[0]['devices'][1]['initial_counter']);
        self::assertSame('2023-12-31', $meters[0]['devices'][1]['installed_on']);
        self::assertCount(2, $this->bills->list('gas'));
    }

    public function testTheMarketLocationAloneJoinsTwoNumbers(): void
    {
        // zwei Rechnungen mit Lücke dazwischen, verschiedene Nummern, dieselbe Marktlokation
        $base = ['utility' => 'gas', 'supplier' => 'Stadtwerke Musterhausen', 'kind' => 'annual', 'market_location' => DraftFixture::MALO_A,
            'total_amount' => '900,00', 'result_kind' => 'due', 'result_amount' => '10,00'];
        $this->doc('a', 'invoice', $base + ['period_from' => '01.01.2022', 'period_to' => '31.12.2022', 'meter_number' => 'GAS-A',
            'reading_old' => '100', 'reading_old_date' => '31.12.2021', 'reading_new' => '1300', 'reading_new_date' => '31.12.2022']);
        $this->doc('b', 'invoice', $base + ['period_from' => '01.07.2024', 'period_to' => '30.06.2025', 'meter_number' => 'GAS-B',
            'reading_old' => '20', 'reading_old_date' => '30.06.2024', 'reading_new' => '1250', 'reading_new_date' => '30.06.2025']);
        $d = $this->build();
        self::assertCount(1, self::items($d, 'meter', 'gas'), 'eine Messstelle über die Marktlokation');
        // der letzte Stand des alten Zählers liegt anderthalb Jahre vor dem Tausch: nicht erschlossen
        self::assertSame([], self::items($d, 'meter_change', 'gas'));
        $m = array_values(array_filter($d['missing'], fn($x) => $x['kind'] === 'old_final'));
        self::assertSame('2024-06-30', $m[0]['params']['date'] ?? null, 'Fehlt noch: Endstand des alten Zählers');
        $r = self::items($d, 'readings', 'gas')[0];
        self::assertSame([true, true], [in_array('swap_open', self::field($r, 'r:2024-06-30')['reasons'], true), self::field($r, 'r:2025-06-30')['check']],
            'Stände des neuen Zählers fragen nach, solange der Tausch fehlt');
        self::assertFalse(self::field($r, 'r:2022-12-31')['check']);
    }

    public function testASwapIsInferredOnlyFromAReadingAtMostSevenDaysBefore(): void
    {
        $base = ['utility' => 'gas', 'supplier' => 'Stadtwerke Musterhausen', 'kind' => 'annual', 'market_location' => DraftFixture::MALO_A,
            'total_amount' => '900,00', 'result_kind' => 'due', 'result_amount' => '10,00'];
        $this->doc('a', 'invoice', $base + ['period_from' => '01.01.2024', 'period_to' => '24.06.2024', 'meter_number' => 'GAS-A',
            'reading_old' => '100', 'reading_old_date' => '31.12.2023', 'reading_new' => '1300', 'reading_new_date' => '24.06.2024']);
        $this->doc('b', 'invoice', $base + ['period_from' => '01.07.2024', 'period_to' => '30.06.2025', 'meter_number' => 'GAS-B',
            'reading_old' => '20', 'reading_old_date' => '30.06.2024', 'reading_new' => '1250', 'reading_new_date' => '30.06.2025']);
        $ch = self::items($this->build(), 'meter_change', 'gas');
        self::assertCount(1, $ch, 'sechs Tage davor: erschlossen');
        self::assertSame([1300.0, true], [self::field($ch[0], 'old_final')['value'], self::field($ch[0], 'old_final')['check']], 'zu prüfen');
    }

    public function testBothReadingsOfASwapComeFromTheMeterReadingsOfTheBill(): void
    {
        // Rechnung mit beiden Zählern (`meter_readings`, wo die Auslesung sie hat): Tausch am 15.05. belegt
        $this->doc('a', 'invoice', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'total_amount' => '700,00', 'result_kind' => 'due', 'result_amount' => '10,00', 'meter_number' => 'STR-NEU',
            'meter_readings' => [
                ['meter_number' => 'STR-ALT', 'reading_old' => '5000', 'reading_old_date' => '31.12.2024', 'reading_new' => '5800', 'reading_new_date' => '15.05.2025'],
                ['meter_number' => 'STR-NEU', 'reading_old' => '0', 'reading_old_date' => '15.05.2025', 'reading_new' => '1300', 'reading_new_date' => '31.12.2025'],
            ]]);
        $d = $this->build();
        self::assertCount(1, self::items($d, 'meter', 'strom'), 'beide Nummern eine Messstelle');
        $ch = self::items($d, 'meter_change', 'strom');
        self::assertCount(1, $ch);
        self::assertSame(['2025-05-15', 5800.0, false, 0.0, true], [self::field($ch[0], 'date')['value'], self::field($ch[0], 'old_final')['value'],
            self::field($ch[0], 'old_final')['check'], self::field($ch[0], 'new_initial')['value'], $ch[0]['checked']]);
        self::assertSame(['r:2024-12-31', 'r:2025-12-31'], array_column(self::items($d, 'readings', 'strom')[0]['fields'], 'name'),
            'Anfangsstand des alten, Endstand des neuen Geräts; am Tauschtag keine Ablesung');
    }

    public function testAFollowingBillWithAnotherNumberIsADeviceChange(): void
    {
        $base = ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'total_amount' => '500,00',
            'result_kind' => 'due', 'result_amount' => '5,00'];
        $this->doc('a', 'invoice', $base + ['period_from' => '01.01.2024', 'period_to' => '31.12.2024', 'meter_number' => 'STR-A',
            'reading_old' => '3000', 'reading_old_date' => '31.12.2023', 'reading_new' => '5000', 'reading_new_date' => '31.12.2024']);
        $this->doc('b', 'invoice', $base + ['period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'meter_number' => 'STR-B',
            'reading_old' => '0', 'reading_old_date' => '01.01.2025', 'reading_new' => '1900', 'reading_new_date' => '31.12.2025']);
        $d = $this->build();
        self::assertCount(1, self::items($d, 'meter', 'strom'), 'lückenlose Folgerechnung mit anderer Nummer: derselbe Zählpunkt');
        self::assertSame('2025-01-01', self::field(self::items($d, 'meter_change', 'strom')[0], 'date')['value']);
        // laufen zwei Nummern nebeneinander (zwei Zähler), bleiben es zwei
        $this->doc('c', 'invoice', $base + ['period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'meter_number' => 'STR-A',
            'reading_old' => '5000', 'reading_old_date' => '31.12.2024', 'reading_new' => '6800', 'reading_new_date' => '31.12.2025', 'total_amount' => '510,00']);
        self::assertCount(2, self::items($this->build(), 'meter', 'strom'), 'zwei Zähler nebeneinander');
    }

    public function testASerialIsOnlyFilledInNeverReplaced(): void
    {
        $dev = $this->meters->get('strom', 'm_strom_default')['devices'][0]['id'];
        $this->meters->setDeviceSerial('strom', 'm_strom_default', $dev, 'STR-1');
        self::assertSame('STR-1', $this->meters->get('strom', 'm_strom_default')['devices'][0]['serial']);
        $this->meters->setDeviceSerial('strom', 'm_strom_default', $dev, 'STR-1');   // dieselbe: nichts zu tun
        $this->expectException(LocalizedException::class);
        $this->meters->setDeviceSerial('strom', 'm_strom_default', $dev, 'STR-2');
    }

    public function testDocumentWithoutMeterNumberFindsItsMeterOverTheContractNumber(): void
    {
        // zwei Stromzähler beim selben Versorger (Haushalt und Wärmepumpe), je ein Vertrag
        foreach ([['h', 'STR-H', 'NL-H'], ['w', 'STR-W', 'NL-W']] as [$t, $serial, $no]) {
            $this->doc("c$t", 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '01.01.2025',
                'tariff_name' => "Tarif $t", 'working_price' => $t === 'h' ? '30,00' : '24,00', 'base_price' => '10,00', 'base_price_period' => 'month',
                'contract_number' => $no, 'meter_number' => $serial]);
        }
        $this->doc('a', 'advance_change', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'advance_new' => '95,00',
            'valid_from' => '01.06.2025', 'contract_number' => 'NL-W']);
        $d = $this->build();
        self::assertCount(2, self::items($d, 'meter', 'strom'));
        $contracts = self::items($d, 'contract', 'strom');
        self::assertCount(2, $contracts, 'gleicher Beginn, verschiedene Vertragsnummern: zwei Verträge');
        $w = array_values(array_filter($contracts, fn($c) => self::field($c, 'contract_number')['value'] === 'NL-W'))[0];
        $adv = self::items($d, 'advances', 'strom');
        self::assertCount(1, $adv);
        self::assertSame($w['contract_key'], $adv[0]['contract_key'], 'der Abschlag gehört zum Vertrag mit seiner Nummer');
        self::assertSame(95.0, self::field($adv[0], 'ap:2025-06-01')['value']);
        self::assertTrue(self::field($adv[0], 'ap:2025-06-01')['check'], 'Abschlagsänderung: Pflichtprüfung');
        $doc = array_values(array_filter($d['documents'], fn($x) => $x['type'] === 'advance_change'))[0];
        self::assertSame($w['meter_key'], $doc['meter_key'], 'Zähler über die Vertragsnummer');
    }

    public function testEndReadingEqualsStartReadingConfirmsBoth(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.01.2024']);
        // beide Belegstellen des Stands am 31.12.2024 falsch: allein wäre er zu prüfen
        $this->tenantInvoice('i24', '01.01.2024', '31.12.2024', '1000', '2800', [], ['reading_new']);
        $d1 = $this->build();
        self::assertTrue(self::field(self::items($d1, 'readings')[0], 'r:2024-12-31')['check'], 'allein: zu prüfen');
        // die Folgerechnung beginnt am 01.01.2025 mit dem Endstand vom Vortag — auch ihre Belegstelle ist falsch
        $this->tenantInvoice('i25', '01.01.2025', '31.12.2025', '2800', '4600', [], ['reading_old']);
        $d = $this->build();
        $r = self::items($d, 'readings')[0];
        $f = self::field($r, 'r:2024-12-31');
        self::assertFalse($f['check'], 'Endstand = Anfangsstand: bestätigt');
        self::assertCount(2, array_unique(array_column($f['sources'], 'doc')), 'beide Belegstellen');
        self::assertNull(self::field($r, 'r:2025-01-01'), 'eine Ablesung, nicht zwei an aufeinanderfolgenden Tagen');
        // stimmen sie nicht überein, ist es eine Frage an beiden Tagen
        $all = $this->store->read('inbox.json', []);
        foreach ($all as $i => $e) if ($e['name'] === 'i25.pdf') $all[$i]['edits']['reading_old'] = 2750;
        $this->store->write('inbox.json', $all);
        $d2 = $this->build();
        self::assertContains('chain_mismatch', array_column($d2['findings'], 'kind'));
        self::assertTrue(self::field(self::items($d2, 'readings')[0], 'r:2025-01-01')['check']);
    }

    public function testAGapBetweenContractsIsReportedNotCorrected(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.01.2024']);
        $this->doc('k1', 'cancellation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_end' => '31.03.2025',
            'cancel_kind' => 'switch', 'contract_number' => 'NL-4401', 'meter_number' => 'STR-1001']);
        $this->doc('c2', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'supply_start' => '15.04.2025',
            'tariff_name' => 'Südwind Fix', 'working_price' => '28,00', 'base_price' => '11,00', 'base_price_period' => 'month', 'meter_number' => 'STR-1001']);
        $d = $this->build();
        $gap = array_values(array_filter($d['findings'], fn($f) => $f['kind'] === 'gap'));
        self::assertCount(1, $gap);
        self::assertSame(['from' => '2025-04-01', 'to' => '2025-04-14'], $gap[0]['params']);
        $cs = self::items($d, 'contract', 'strom');
        self::assertCount(2, $cs);
        self::assertSame('2025-03-31', self::field($cs[0], 'end')['value'], 'das Ende aus der Kündigung bleibt');
        self::assertSame('document', self::field($cs[0], 'end')['basis']);
        self::assertNull(self::field($cs[1], 'end'));
        self::assertCount(1, self::items($d, 'meter', 'strom'), 'Lieferantenwechsel: derselbe Zähler');
    }

    public function testTwoContractsWithTheSameStartAreReportedNotEndedBeforeTheyBegin(): void
    {
        // zwei Versorger am selben Zähler ab demselben Tag: gemeldet, kein Ende vor dem Beginn
        foreach ([['c1', 'Nordlicht Energie GmbH', 'NL-1'], ['c2', 'Südwind Strom AG', 'SW-1']] as [$tag, $supplier, $no]) {
            $this->doc($tag, 'contract_confirmation', ['utility' => 'strom', 'supplier' => $supplier, 'supply_start' => '01.01.2025',
                'working_price' => '30,00', 'contract_number' => $no, 'meter_number' => 'STR-3001']);
        }
        $d = $this->build();
        $cs = self::items($d, 'contract', 'strom');
        self::assertCount(2, $cs);
        foreach ($cs as $c) self::assertNull(self::field($c, 'end'), 'kein Ende am Tag vor dem eigenen Beginn');
        self::assertContains('overlap', array_column($d['findings'], 'kind'));
    }

    public function testContractEndsTheDayBeforeItsSuccessor(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.01.2024']);
        $this->doc('c2', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'supply_start' => '01.07.2025',
            'tariff_name' => 'Südwind Fix', 'meter_number' => 'STR-1001']);
        $cs = self::items($this->build(), 'contract', 'strom');
        self::assertSame(['2025-06-30', 'successor'], [self::field($cs[0], 'end')['value'], self::field($cs[0], 'end')['basis']]);
    }

    public function testDocumentsWithTheSameContentCountOnce(): void
    {
        $a = $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $b = $this->tenantConfirmation('c1-kopie', ['supply_start' => '01.04.2025']);
        $d = $this->build();
        self::assertSame(1, $d['summary']['duplicates']);
        $dup = array_values(array_filter($d['documents'], fn($x) => $x['state'] === 'duplicate'))[0];
        self::assertSame([$b, $a], [$dup['id'], $dup['duplicate_of']]);
        self::assertCount(1, self::items($d, 'contract'));
        self::assertNotContains($b, self::items($d, 'contract')[0]['docs'], 'einmal gezählt');
        self::assertSame($a, array_column($this->inbox->list(), 'duplicate_of', 'id')[$b], 'dieselbe Quelle wie die Liste');
        $this->applyChecked($d);
        self::assertSame('done', $this->inbox->get($a)['status']);
        $x = $this->inbox->get($b);
        self::assertSame('filed', $x['status'], 'abgelegt, die Datei bleibt');
        self::assertArrayNotHasKey('duplicate_of', $x, 'nicht gespeichert: die Liste rechnet es aus dem Inhalt');
        self::assertSame($a, array_column($this->inbox->list(), 'duplicate_of', 'id')[$b]);
        self::assertCount(1, $this->contracts->list('strom'));
    }

    public function testACopyOfADocumentAlreadyTakenOverCountsOnce(): void
    {
        $a = $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->applyChecked($this->build());
        self::assertSame('done', $this->inbox->get($a)['status']);
        $b = $this->tenantConfirmation('c1-kopie', ['supply_start' => '01.04.2025']);
        $d = $this->build();
        $doc = array_column($d['documents'], null, 'id')[$b];
        self::assertSame(['duplicate', $a], [$doc['state'], $doc['duplicate_of']], 'wie in der Liste, auch wenn das Original schon übernommen ist');
        self::assertSame([], array_values(array_filter($d['items'], fn($i) => $i['actionable'])), 'nichts doppelt');
    }

    // ── Marken und offene Punkte (aus dem Posteingang) ─────────────────

    public function testAnInvoicePriceAsInTheContractIsAlreadyThere(): void
    {
        // Marken nach dem heutigen Bestand (Proposals::marks): Der Preis passt zum
        // Vertrag an dem Tag (`price_matches`) — keine neue Zeile, keine Frage
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['devices'][0]['serial'] = 'STR-1001';
        $all[0]['devices'][0]['installed_on'] = '2024-01-01';
        $this->store->write('strom/meters.json', $all);
        $this->contracts->create('strom', ['meter_id' => 'm_strom_default', 'provider' => 'Nordlicht Energie GmbH', 'tariff_name' => 'Nordlicht Basis 12',
            'start' => '2025-04-01', 'contract_number' => 'NL-4401', 'working_prices' => [['from' => '2025-04-01', 'ct_per_kwh' => 31.2]],
            'base_prices' => [['from' => '2025-04-01', 'eur_per_month' => 12.9]]]);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3800', ['working_prices' => [['from' => '01.06.2025', 'ct' => '31,20']]]);
        $p = self::items($this->build(), 'prices', 'strom')[0];
        $f = self::field($p, 'wp:2025-06-01');
        self::assertSame(['exists', false], [$f['status'], $f['check']], json_encode($f));
        self::assertFalse($p['actionable']);
    }

    public function testWhatADocumentLacksStandsUnderStillMissing(): void
    {
        $c1 = $this->doc('c1', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'tariff_name' => 'Nordlicht Basis 12',
            'supply_start' => '01.04.2025', 'working_price' => '31,20', 'base_price' => '12,90', 'base_price_period' => 'month',
            'contract_number' => 'NL-4401', 'meter_number' => 'STR-1001'], ['tariff_name']);
        // ein zweiter Brief zum selben Vertrag, auch ohne Kündigungsfrist: eine Zeile, nicht zwei
        $c2 = $this->doc('c2', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'tariff_name' => 'Nordlicht Basis 12 Online',
            'supply_start' => '01.04.2025', 'working_price' => '31,20', 'contract_number' => 'NL-4401', 'meter_number' => 'STR-1001']);
        $d = $this->build();
        $open = array_values(array_filter($d['missing'], fn($m) => $m['kind'] === 'open'));
        self::assertCount(1, $open, json_encode($d['missing']));
        self::assertSame([[$c1, $c2], '#/inbox/' . $c1, 'c1.pdf, c2.pdf'], [$open[0]['docs'], $open[0]['href'], $open[0]['params']['name']]);
        self::assertSame([['field' => 'notice_period', 'reason' => 'missing']], $open[0]['open'], 'offener Punkt des Vorschlags: Kündigungsfrist fehlt im Dokument');
        self::assertContains('advance', array_column($d['missing'], 'kind'), 'der Abschlag steht je Vertrag da, nicht noch einmal je Dokument');
        self::assertTrue(self::field(self::items($d, 'contract', 'strom')[0], 'tariff_name')['check'],
            'unsicher gelesen: der Eintrag fragt — nicht noch einmal unter „Fehlt noch“');
    }

    public function testAfterApplyingTheFileShowsTheDocumentsAtTheContract(): void
    {
        $c1 = $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $i1 = $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3800');
        $this->applyChecked($this->build());
        $contract = $this->contracts->list('strom')[0];
        $inbox = array_column(array_filter($this->dossier->forContract('strom', (string)$contract['id']), fn($x) => $x['kind'] === 'inbox'), null, 'inbox_id');
        self::assertSame(['done', 'done'], [$inbox[$c1]['status'] ?? null, $inbox[$i1]['status'] ?? null], 'beide Dokumente in der Akte des Vertrags');
        self::assertNotEmpty($inbox[$i1]['bill_id'] ?? null, 'das Dokument trägt seine Rechnung');
        // Kernaussage nach dem Übernehmen wie beim einzelnen Dokument: „Rechnung prüfen“ läuft mit
        $g = $this->inbox->get($i1)['gist'] ?? null;
        self::assertIsArray($g);
        self::assertNotSame('inbox.gist.invoice.pending', $g['key']);
    }

    // ── Preise ──────────────────────────────────────────────────────────

    public function testInvoicePricesConfirmTheChainOrAreAFindingWhenTheyDiffer(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->doc('p1', 'price_change', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'valid_from' => '01.01.2026',
            'working_price_old' => '31,20', 'working_price_new' => '32,50', 'contract_number' => 'NL-4401']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3500',
            ['working_prices' => [['from' => '01.04.2025', 'ct' => '31,20'], ['from' => '01.07.2025', 'ct' => '31,20'], ['from' => '01.10.2025', 'ct' => '33,00']]]);
        $d = $this->build();
        $p = self::items($d, 'prices', 'strom')[0];
        self::assertNull(self::field($p, 'wp:2025-07-01'), 'derselbe Preis an einem anderen Tag: bestätigt die Kette, keine neue Zeile');
        self::assertCount(3, self::field($p, 'wp:2025-04-01')['sources'], 'Bestätigung und beide passenden Rechnungszeilen');
        self::assertFalse(self::field($p, 'wp:2025-04-01')['check'], 'Rechnungspreis passt zur Kette: bestätigt');
        self::assertNull(self::field($p, 'wp:2025-10-01'), 'abweichender Rechnungspreis: kein Glied der Kette');
        $f = array_values(array_filter($d['findings'], fn($x) => $x['kind'] === 'invoice_wp'));
        self::assertSame([['name' => 'i1.pdf', 'date' => '2025-10-01', 'invoice' => 33.0, 'chain' => 31.2]], array_column($f, 'params'), 'ein Befund unter „Aufgefallen“');
        self::assertFalse(self::field($p, 'wp:2026-01-01')['check'], 'Preisänderung');
        self::assertTrue($p['checked']);
        self::assertNotContains('prices', array_column($d['missing'], 'kind'));
        $this->applyChecked($d);
        $c = $this->contracts->list('strom')[0];
        self::assertSame(['2025-04-01', '2026-01-01'], array_column($c['working_prices'], 'from'), 'ohne den abweichenden Preis');
        self::assertSame([31.2, 32.5], array_column($c['working_prices'], 'ct_per_kwh'));
    }

    public function testTheConfirmationPriceIsPreselectedEvenIfTheBillSaysOtherwise(): void
    {
        // Rechnung am selben Tag mit anderem Preis (netto statt brutto): bis dahin eine Entscheidung,
        // ohne Wahl blieb der Vertrag ohne Arbeitspreis
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3500', ['working_prices' => [['from' => '01.04.2025', 'ct' => '26,22']],
            'base_price' => '10,84', 'base_price_period' => 'month']);
        $d = $this->build();
        $p = self::items($d, 'prices', 'strom')[0];
        $wp = self::field($p, 'wp:2025-04-01');
        self::assertSame([31.2, false], [$wp['value'], $wp['check']], 'der Preis der Bestätigung, vorbelegt');
        self::assertArrayNotHasKey('options', $wp);
        self::assertSame([12.9, false], [self::field($p, 'bp:2025-04-01')['value'], self::field($p, 'bp:2025-04-01')['check']]);
        self::assertEqualsCanonicalizing(['invoice_wp', 'invoice_bp'], array_values(array_intersect(array_column($d['findings'], 'kind'), ['invoice_wp', 'invoice_bp'])));
        $this->applyChecked($d);
        $c = $this->contracts->list('strom')[0];
        self::assertSame([31.2], array_column($c['working_prices'], 'ct_per_kwh'));
        self::assertSame([12.9], array_column($c['base_prices'], 'eur_per_month'));
    }

    public function testANetBillPriceIsComparedGross(): void
    {
        // Rechnung netto zzgl. 19 % (wie im Posteingang umgerechnet, priceOf): 26,22 × 1,19 = 31,20 — bestätigt die Kette
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3500', ['working_prices' => [['from' => '01.04.2025', 'ct' => '26,22', 'basis' => 'net', 'vat_pct' => '19 %']],
            'base_price' => '10,84', 'base_price_period' => 'month', 'base_price_basis' => 'net', 'base_price_vat_pct' => '19 %']);
        $d = $this->build();
        $wp = self::field(self::items($d, 'prices', 'strom')[0], 'wp:2025-04-01');
        self::assertSame([31.2, false], [$wp['value'], $wp['check']]);
        self::assertCount(2, $wp['sources'], 'Bestätigung und die umgerechnete Rechnungszeile');
        self::assertNotContains('invoice_wp', array_column($d['findings'], 'kind'), 'netto ist kein Widerspruch');
        self::assertNotContains('invoice_bp', array_column($d['findings'], 'kind'), 'Grundpreis netto 10,84 + 19 % = 12,90');
        // nur Rechnungen: der Vertrag bekommt brutto
        $this->resetInbox();
        $this->tenantInvoice('i2', '01.01.2026', '31.12.2026', '3500', '5300', ['working_prices' => [['from' => '01.01.2026', 'ct' => '30,00', 'basis' => 'net', 'vat_pct' => '19 %']]]);
        $f = self::field(self::items($this->build(), 'prices', 'strom')[0], 'wp:2026-01-01');
        self::assertSame(35.7, $f['value'], 'brutto in die Kette');
    }

    public function testTheSameNetPriceWithAnotherRateIsANewRowNotAContradiction(): void
    {
        // Bestätigung brutto inkl. 7 % (10,70 = 10,00 netto); die Rechnung nennt ab 01.07. netto 10,00 zzgl. 19 %
        $this->doc('c1', 'contract_confirmation', ['utility' => 'gas', 'supplier' => 'Musterhausen-Energie GmbH', 'supply_start' => '01.01.2025',
            'working_price' => '10,70', 'base_price' => '10,00', 'base_price_period' => 'month', 'vat_pct' => '7 %', 'meter_number' => 'GAS-9']);
        $this->doc('i1', 'invoice', ['utility' => 'gas', 'supplier' => 'Musterhausen-Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'reading_old' => '100', 'reading_old_date' => '31.12.2024', 'reading_new' => '1300', 'reading_new_date' => '31.12.2025',
            'total_amount' => '1300,00', 'result_kind' => 'credit', 'result_amount' => '20,00', 'meter_number' => 'GAS-9',
            'working_prices' => [['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '7 %'], ['from' => '01.07.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '19 %']]]);
        $d = $this->build();
        $p = self::items($d, 'prices', 'gas')[0];
        $new = self::field($p, 'wp:2025-07-01');
        self::assertSame([11.9, false, 'vat_changed'], [$new['value'] ?? null, $new['check'] ?? null, $new['basis'] ?? null], 'neue Zeile brutto ab dem Tag der Rechnung, vorbelegt');
        self::assertSame(10.7, self::field($p, 'wp:2025-01-01')['value']);
        self::assertNotContains('invoice_wp', array_column($d['findings'], 'kind'), 'anderer Steuersatz ist kein Widerspruch');
    }

    public function testAContractWithoutPricesStandsUnderStillMissing(): void
    {
        // nur Rechnungspreise (zu prüfen): ohne Bestätigung fehlt der Preis nach dem Übernehmen — „Fehlt noch“ sagt es
        $this->tenantInvoice('i1', '01.01.2025', '31.12.2025', '2000', '3500', ['working_prices' => [['from' => '01.01.2025', 'ct' => '31,20']]]);
        $d = $this->build();
        $m = array_values(array_filter($d['missing'], fn($x) => str_starts_with($x['kind'], 'price') || in_array($x['kind'], ['working_price', 'base_price'], true)));
        self::assertSame(['prices_unconfirmed', ['wp', 'bp']], [$m[0]['kind'] ?? null, $m[0]['which'] ?? null], json_encode($d['missing']));
        // bestätigt man die Rechnungspreise, fehlen sie nach dem Übernehmen nicht mehr
        $this->resetInbox();
        $this->tenantInvoice('i2', '01.01.2025', '31.12.2025', '2000', '3500', ['working_prices' => [['from' => '01.01.2025', 'ct' => '31,20']],
            'base_price' => '12,90', 'base_price_period' => 'month']);
        $d = $this->build();
        $p = self::items($d, 'prices', 'strom')[0];
        self::assertContains('prices_unconfirmed', array_column($d['missing'], 'kind'));
        $res = $this->applyChecked($d, [$p['id'] => array_column(array_filter($p['fields'], fn($f) => $f['check']), 'name')]);
        self::assertNotContains('prices_unconfirmed', array_column($res['missing'], 'kind'), json_encode($res['missing']));
        foreach ($this->contracts->list('strom') as $x) $this->contracts->delete('strom', (string)$x['id']);
        // ein Lieferbeginn-Brief ohne Preise: beides fehlt, in keinem Dokument
        $this->resetInbox();
        $this->doc('l1', 'supply_start', ['utility' => 'gas', 'supplier' => 'Musterhausen-Energie GmbH', 'supply_start' => '01.01.2025', 'meter_number' => 'GAS-9']);
        $m = array_values(array_filter($this->build()['missing'], fn($x) => $x['utility'] === 'gas'));
        self::assertSame(['prices', ['wp', 'bp']], [$m[0]['kind'] ?? null, $m[0]['which'] ?? null], json_encode($m));
        // nur der Arbeitspreis steht in der Bestätigung: es fehlt der Grundpreis
        $this->resetInbox();
        $this->doc('c2', 'contract_confirmation', ['utility' => 'gas', 'supplier' => 'Musterhausen-Energie GmbH', 'supply_start' => '01.01.2025',
            'working_price' => '9,80', 'meter_number' => 'GAS-9']);
        $m = array_values(array_filter($this->build()['missing'], fn($x) => $x['utility'] === 'gas' && $x['kind'] !== 'advance'));
        self::assertSame(['base_price', ['bp']], [$m[0]['kind'] ?? null, $m[0]['which'] ?? null], json_encode($m));
    }

    // ── Nachbesserung nach Nutzentest 2 ─────────────────────────────────

    public function testAConfirmationWithoutStartFindsItsLetterOverTheNumber(): void
    {
        // zwei Briefe desselben Versorgers; der eine trägt die Vertragsnummer der Bestätigung (der andere
        // gehört zu einem zweiten Vertrag) — ohne die Nummer wäre es mehrdeutig
        $this->tenantConfirmation('c1');
        $this->doc('l1', 'supply_start', ['utility' => 'strom', 'supplier' => 'Ostwind Netz-Service', 'supply_start' => '01.04.2025',
            'meter_number' => 'STR-1001', 'contract_number' => 'NL-4401']);
        $this->doc('l2', 'supply_start', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '01.02.2025',
            'meter_number' => 'STR-2002', 'contract_number' => 'NL-9999']);
        $c = array_values(array_filter(self::items($this->build(), 'contract', 'strom'), fn($x) => (self::field($x, 'tariff_name')['value'] ?? null) === 'Nordlicht Basis 12'));
        self::assertCount(1, $c, 'die Bestätigung hat einen Vertrag');
        self::assertSame(['2025-04-01', 'supply_start'], [self::field($c[0], 'start')['value'], self::field($c[0], 'start')['basis']], 'Beginn aus dem Brief mit ihrer Nummer');
    }

    public function testABrandNamedNextToItsSupplierJoinsBoth(): void
    {
        // Bestätigung unter der Marke, Brief vom Versorger, eine Rechnung nennt beide
        $this->doc('c1', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Musterstrom', 'tariff_name' => 'Musterstrom Fix',
            'working_price' => '29,00', 'base_price' => '11,00', 'base_price_period' => 'month']);
        $this->doc('l1', 'supply_start', ['utility' => 'strom', 'supplier' => 'Musterwerk Energie GmbH', 'supply_start' => '01.03.2025', 'meter_number' => 'STR-5001']);
        $this->doc('i1', 'invoice', ['utility' => 'strom', 'supplier' => 'Musterstrom, eine Marke der Musterwerk Energie GmbH', 'kind' => 'annual',
            'period_from' => '01.03.2025', 'period_to' => '28.02.2026', 'reading_old' => '100', 'reading_old_date' => '01.03.2025',
            'reading_new' => '2100', 'reading_new_date' => '28.02.2026', 'total_amount' => '700,00', 'result_kind' => 'due', 'result_amount' => '10,00',
            'meter_number' => 'STR-5001']);
        $d = $this->build();
        $c = self::items($d, 'contract', 'strom');
        self::assertCount(1, $c, json_encode(array_map(fn($x) => $x['params'], $c)));
        self::assertSame('2025-03-01', self::field($c[0], 'start')['value'], 'Beginn aus dem Brief des Versorgers');
        self::assertSame(29.0, self::field(self::items($d, 'prices', 'strom')[0], 'wp:2025-03-01')['value']);
        self::assertSame($c[0]['contract_key'], self::items($d, 'bill', 'strom')[0]['contract_key']);
    }

    public function testAConfirmationWithoutStartJoinsOverTheSamePrices(): void
    {
        $this->doc('c1', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'tariff_name' => 'Südwind Fix 24',
            'working_price' => '28,00', 'base_price' => '11,00', 'base_price_period' => 'month']);
        $this->doc('i1', 'invoice', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'kind' => 'annual', 'period_from' => '15.01.2025',
            'period_to' => '31.12.2025', 'reading_old' => '5000', 'reading_old_date' => '15.01.2025', 'reading_new' => '7400', 'reading_new_date' => '31.12.2025',
            'total_amount' => '800,00', 'result_kind' => 'due', 'result_amount' => '20,00', 'meter_number' => 'STR-1001',
            'working_prices' => [['from' => '15.01.2025', 'ct' => '28,00']], 'base_price' => '11,00', 'base_price_period' => 'month']);
        $d = $this->build();
        $c = self::items($d, 'contract', 'strom');
        self::assertCount(1, $c);
        self::assertSame(['2025-01-15', 'invoice'], [self::field($c[0], 'start')['value'], self::field($c[0], 'start')['basis']], 'Beginn mit der ersten Rechnung zu diesem Preis');
        self::assertSame('Südwind Fix 24', self::field($c[0], 'tariff_name')['value'], 'die Bestätigung gehört dazu');
        self::assertNotContains('contract_start', array_column($d['missing'], 'kind'));
    }

    public function testWithoutAStartThereIsNoContractAndNoEmptyMeter(): void
    {
        // Bestätigung ohne Beginn, ohne Nummer, ohne Brief: nur „Fehlt noch“ — kein leerer Zähler
        $this->doc('c1', 'contract_confirmation', ['utility' => 'gas', 'supplier' => 'Musterhausen-Energie GmbH', 'tariff_name' => 'Gas Klassik',
            'working_price' => '9,80']);
        $this->doc('c2', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'tariff_name' => 'Basis',
            'working_price' => '31,20']);
        $this->doc('c3', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'tariff_name' => 'Wärmepumpe',
            'working_price' => '24,00']);
        $this->doc('l1', 'supply_start', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '01.01.2025']);
        $this->doc('l2', 'supply_start', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '01.02.2025']);
        $d = $this->build();
        self::assertSame([], self::items($d, 'meter', 'gas'), 'kein Zähler ohne Nummer und Daten');
        self::assertSame([], self::items($d, 'contract', 'gas'));
        self::assertContains('contract_start', array_column(array_filter($d['missing'], fn($m) => $m['utility'] === 'gas'), 'kind'));
        $docs = array_column($d['documents'], 'state', 'name');
        self::assertSame('no_start', $docs['c1.pdf']);
        // zwei Bestätigungen ohne Beginn, zwei Briefe desselben Versorgers: mehrdeutig — keine Schätzung
        self::assertSame([], array_values(array_filter(self::items($d, 'contract', 'strom'), fn($c) => in_array('c2.pdf', array_column(self::field($c, 'provider')['sources'] ?? [], 'name'), true))));
        self::assertSame(['no_start', 'no_start'], [$docs['c2.pdf'], $docs['c3.pdf']]);
    }

    public function testBillsBeforeTheStartMoveItForwardOrFormAPredecessor(): void
    {
        // dieselbe Vertragsnummer: der Vertrag beginnt mit der ersten Rechnung (mit Hinweis)
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('i0', '01.04.2024', '31.03.2025', '200', '2000');
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3500');
        $d = $this->build();
        $c = self::items($d, 'contract', 'strom');
        self::assertCount(1, $c);
        self::assertSame(['2024-04-01', 'invoice_earlier'], [self::field($c[0], 'start')['value'], self::field($c[0], 'start')['basis']]);
        self::assertContains('start_from_invoice', array_column($d['findings'], 'kind'));
        foreach (self::items($d, 'bill', 'strom') as $b) self::assertSame($c[0]['contract_key'], $b['contract_key'], 'keine Rechnung ohne Vertrag');
        self::assertSame(31.2, self::field(self::items($d, 'prices', 'strom')[0], 'wp:2025-04-01')['value'], 'die Preise der Bestätigung ab ihrem Beginn');
        $res = $this->applyChecked($d);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        self::assertSame(2, $res['created']['booked'], 'beide Ergebnisse gebucht');
        // eine andere Vertragsnummer: ein Vorvertrag aus der ersten Rechnung, am Zähler der Rechnungen
        $this->resetInbox();
        foreach ($this->contracts->list('strom') as $x) $this->contracts->delete('strom', (string)$x['id']);
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('i0', '01.04.2024', '31.03.2025', '200', '2000', ['contract_number' => 'NL-1999']);
        $d = $this->build();
        $c = self::items($d, 'contract', 'strom');
        usort($c, fn($a, $b) => strcmp(self::field($a, 'start')['value'], self::field($b, 'start')['value']));
        self::assertCount(2, $c, 'Vorvertrag und Vertrag');
        self::assertSame(['2024-04-01', '2025-03-31'], [self::field($c[0], 'start')['value'], self::field($c[0], 'end')['value'] ?? null]);
        self::assertSame($c[1]['meter_key'], $c[0]['meter_key'], 'am selben Zähler');
        self::assertSame($c[0]['contract_key'], self::items($d, 'bill', 'strom')[0]['contract_key']);
    }

    public function testTheContractOfThePreviousSupplierSitsAtTheMeterOfItsBills(): void
    {
        // Vorversorger: Kündigung ohne Zählernummer, Rechnung mit Nummer; neuer Versorger am selben Zähler
        $this->doc('k0', 'cancellation', ['utility' => 'strom', 'supplier' => 'Westlicht Strom GmbH', 'supply_end' => '31.03.2025', 'cancel_kind' => 'switch']);
        $this->doc('i0', 'invoice', ['utility' => 'strom', 'supplier' => 'Westlicht Strom GmbH', 'kind' => 'final', 'period_from' => '01.04.2024',
            'period_to' => '31.03.2025', 'reading_old' => '200', 'reading_old_date' => '31.03.2024', 'reading_new' => '2000', 'reading_new_date' => '31.03.2025',
            'total_amount' => '600,00', 'result_kind' => 'credit', 'result_amount' => '15,00', 'meter_number' => 'STR-1001']);
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $d = $this->build();
        self::assertCount(1, self::items($d, 'meter', 'strom'), 'kein zweiter, leerer Zähler');
        $c = array_column(array_map(fn($x) => ['p' => self::field($x, 'provider')['value'], 'mk' => $x['meter_key']], self::items($d, 'contract', 'strom')), 'mk', 'p');
        $readings = self::items($d, 'readings', 'strom')[0];
        self::assertSame($readings['meter_key'], $c['Westlicht Strom GmbH'] ?? null, 'der Vorvertrag am Zähler seiner Rechnung');
        self::assertSame($readings['meter_key'], $c['Nordlicht Energie GmbH'] ?? null);
    }

    /**
     * Vertragskette aus dem Entwurf im Saldo (wirklichkeit-2: ein abgelöster Vertrag endet am Tag vor
     * dem Folgevertrag). Endet der Vorvertrag laut Entwurf am Tag davor, ist er nicht noch einmal
     * abgelöst; nur ein Ende, das in den Folgevertrag hineinreicht (gemeldet als Überschneidung,
     * „es gilt der spätere Beginn“), endet im Saldo am Tag davor, gespeichert bleibt es, wie es steht.
     *
     * @return array<string,array<string,mixed>> Vertragsstand je Anbieter
     */
    private function chainInTheBalance(array $d): array
    {
        $this->applyChecked($d, [], [], array_column(array_filter($d['items'], fn($i) => $i['actionable'] && !$i['checked']), 'id'));
        $out = [];
        foreach ($this->meters->list('strom') as $m) {
            foreach ($this->consumption->contractStatus('strom', $m)['contracts'] as $s) {
                $c = $this->contracts->get('strom', (string)$s['contract_id']);
                $out[(string)$c['provider']] = ['end' => $c['end'] ?? null, 'superseded_on' => $s['superseded_on']];
            }
        }
        ksort($out);
        return $out;
    }

    public function testAChainFromTheDraftEndsOnceInTheBalance(): void
    {
        // Vorversorger ohne Ende in den Dokumenten (Jahresrechnung): Ende am Tag vor dem Folgevertrag
        $this->doc('i0', 'invoice', ['utility' => 'strom', 'supplier' => 'Westlicht Strom GmbH', 'kind' => 'annual', 'period_from' => '01.04.2024',
            'period_to' => '31.03.2025', 'reading_old' => '200', 'reading_old_date' => '31.03.2024', 'reading_new' => '2000', 'reading_new_date' => '31.03.2025',
            'total_amount' => '600,00', 'result_kind' => 'credit', 'result_amount' => '15,00', 'meter_number' => 'STR-1001']);
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        self::assertSame([
            'Nordlicht Energie GmbH' => ['end' => null, 'superseded_on' => null],
            'Westlicht Strom GmbH' => ['end' => '2025-03-31', 'superseded_on' => null],
        ], $this->chainInTheBalance($this->build()), 'lückenlos: kein zweites Ende');
    }

    public function testAnOverlappingEndFromTheDocumentsEndsInTheBalanceAtTheLaterStart(): void
    {
        // Kündigung und Schlussrechnung nennen den 30.04., der Folgevertrag beginnt am 01.04.
        $this->doc('k0', 'cancellation', ['utility' => 'strom', 'supplier' => 'Westlicht Strom GmbH', 'supply_end' => '30.04.2025', 'cancel_kind' => 'switch']);
        $this->doc('i0', 'invoice', ['utility' => 'strom', 'supplier' => 'Westlicht Strom GmbH', 'kind' => 'final', 'period_from' => '01.05.2024',
            'period_to' => '30.04.2025', 'reading_old' => '200', 'reading_old_date' => '30.04.2024', 'reading_new' => '2000', 'reading_new_date' => '30.04.2025',
            'total_amount' => '600,00', 'result_kind' => 'credit', 'result_amount' => '15,00', 'meter_number' => 'STR-1001']);
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $d = $this->build();
        $o = array_values(array_filter($d['findings'], fn($f) => $f['kind'] === 'overlap'));
        self::assertSame(['2025-04-01', '2025-04-30'], [$o[0]['params']['from'] ?? null, $o[0]['params']['to'] ?? null], 'gemeldet');
        self::assertSame([
            'Nordlicht Energie GmbH' => ['end' => null, 'superseded_on' => null],
            'Westlicht Strom GmbH' => ['end' => '2025-04-30', 'superseded_on' => '2025-03-31'],
        ], $this->chainInTheBalance($d), 'im Saldo gilt der spätere Beginn, einmal');
    }

    public function testOneSwapIsOneDeviceChangeWithTheReadingFromTheBill(): void
    {
        // alter Zähler bis zum Jahresende, Brief ohne Nummer mit dem Anfangsstand am 1., neuer Zähler ab dem 2.:
        // Sprung und Nummernwechsel sind derselbe Tausch
        $base = ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'total_amount' => '500,00',
            'result_kind' => 'due', 'result_amount' => '5,00', 'contract_number' => 'NL-4401'];
        $this->doc('a', 'invoice', $base + ['period_from' => '01.01.2024', 'period_to' => '31.12.2024', 'meter_number' => 'STR-A',
            'reading_old' => '3000', 'reading_old_date' => '31.12.2023', 'reading_new' => '5000', 'reading_new_date' => '31.12.2024']);
        $this->doc('l', 'supply_start', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '01.01.2025', 'reading_start' => '10',
            'contract_number' => 'NL-4401']);
        $this->doc('b', 'invoice', $base + ['period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'meter_number' => 'STR-B',
            'reading_old' => '10', 'reading_old_date' => '02.01.2025', 'reading_new' => '1900', 'reading_new_date' => '31.12.2025']);
        $d = $this->build();
        $ch = self::items($d, 'meter_change', 'strom');
        self::assertCount(1, $ch, 'ein Tausch, ein Gerätewechsel');
        self::assertSame(['2025-01-01', 5000.0, false, true], [self::field($ch[0], 'date')['value'], self::field($ch[0], 'old_final')['value'],
            self::field($ch[0], 'old_final')['check'], $ch[0]['checked']], 'Endstand aus der Rechnung, vorbelegt');
        self::assertNotContains('falling', array_column($d['findings'], 'kind'));
        self::assertNotContains('chain_mismatch', array_column($d['findings'], 'kind'));
        $this->applyChecked($d, [], [], array_column(array_filter($d['items'], fn($i) => $i['actionable'] && !$i['checked']), 'id'));
        self::assertCount(2, $this->meters->list('strom')[0]['devices'], 'zwei Geräte, nicht drei');
    }

    public function testASwapBetweenTwoBillsIsPreselected(): void
    {
        // Endstand am 31.12., neuer Zähler ab dem 01.01.: Nummernwechsel und Sprung passen zusammen
        $base = ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'total_amount' => '500,00',
            'result_kind' => 'due', 'result_amount' => '5,00'];
        $this->doc('a', 'invoice', $base + ['period_from' => '01.01.2024', 'period_to' => '31.12.2024', 'meter_number' => 'STR-A',
            'reading_old' => '3000', 'reading_old_date' => '31.12.2023', 'reading_new' => '5000', 'reading_new_date' => '31.12.2024']);
        $this->doc('b', 'invoice', $base + ['period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'meter_number' => 'STR-B',
            'reading_old' => '0', 'reading_old_date' => '01.01.2025', 'reading_new' => '1900', 'reading_new_date' => '31.12.2025']);
        $ch = self::items($this->build(), 'meter_change', 'strom');
        self::assertSame([5000.0, false, true], [self::field($ch[0], 'old_final')['value'], self::field($ch[0], 'old_final')['check'], $ch[0]['checked']]);
    }

    public function testTheSameIdentifierInTwoDocumentsIsConfirmed(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025', 'contract_number' => 'NL 4401', 'customer_number' => '0815']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3500', ['customer_number' => '815'], ['customer_number']);
        $c = self::items($this->build(), 'contract', 'strom')[0];
        foreach (['contract_number', 'customer_number'] as $n) {
            $f = self::field($c, $n);
            self::assertFalse($f['check'], "$n: gleich in zwei Dokumenten, ohne Frage");
            self::assertArrayNotHasKey('options', $f);
        }
    }

    public function testACorrectedBillReplacesTheEarlierOne(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $old = $this->tenantInvoice('rechnung', '01.04.2025', '31.12.2025', '2000', '3500');
        $new = $this->tenantInvoice('rechnung-korrektur', '01.04.2025', '31.12.2025', '2000', '3500',
            ['total_amount' => '620,00', 'result_kind' => 'due', 'result_amount' => '20,00']);
        $d = $this->build();
        $bills = self::items($d, 'bill', 'strom');
        self::assertCount(1, $bills);
        $total = self::field($bills[0], 'total_amount');
        self::assertSame([620.0, false], [$total['value'], $total['check']], 'die korrigierte Fassung gilt, vorgewählt');
        self::assertArrayNotHasKey('options', $total);
        self::assertSame([20.0, false], [self::field($bills[0], 'result')['value'], self::field($bills[0], 'result')['check']]);
        self::assertTrue($bills[0]['checked']);
        self::assertContains('bill_corrected', array_column($d['findings'], 'kind'));
        $this->applyChecked($d);
        self::assertSame(620.0, (float)$this->bills->list('strom')[0]['invoice']['amount_eur']);
        self::assertSame(['done', 'done'], [$this->inbox->get($old)['status'], $this->inbox->get($new)['status']], 'die frühere ist Beleg und erledigt');
    }

    public function testADoubtfulEndIsTheDayBeforeTheSuccessor(): void
    {
        $this->doc('c0', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_start' => '01.01.2024',
            'working_price' => '31,20', 'contract_number' => 'NL-4401', 'meter_number' => 'STR-1001']);
        $this->doc('k0', 'cancellation', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'supply_end' => '30.12.2024', 'cancel_kind' => 'switch',
            'contract_number' => 'NL-4401'], ['supply_end']);
        $this->doc('c1', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'supply_start' => '01.01.2025',
            'working_price' => '28,00', 'contract_number' => 'SW-77', 'meter_number' => 'STR-1001']);
        $d = $this->build();
        $old = array_values(array_filter(self::items($d, 'contract', 'strom'), fn($c) => self::field($c, 'start')['value'] === '2024-01-01'))[0];
        $end = self::field($old, 'end');
        self::assertSame(['2024-12-31', false, 'successor'], [$end['value'], $end['check'], $end['basis']], 'zweifelhaftes Ende: der Tag vor dem Folgevertrag, vorbelegt');
        self::assertSame([], array_values(array_filter($d['findings'], fn($f) => in_array($f['kind'], ['gap', 'overlap'], true))));
    }

    public function testTwoConfirmationsWithTheSameStartAtOneMeterAreOneContract(): void
    {
        // Auftragsbestätigung und Willkommensbrief, einer mit Kundennummer statt Vertragsnummer
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantConfirmation('c2', ['supply_start' => '01.04.2025', 'contract_number' => 'K-778899', 'tariff_name' => 'Nordlicht Basis 12 Online']);
        self::assertCount(1, self::items($this->build(), 'contract', 'strom'), 'gleicher Beginn, gleicher Versorger, gleicher Zähler: ein Vertrag');
    }

    // ── Nachbesserung 3 ─────────────────────────────────────────────────

    public function testABillPriceAsTheOnlySourceIsPreselectedWhenNetOrGrossIsKnown(): void
    {
        // brutto inkl. 19 % ausgewiesen (Steuer gelesen): vorbelegt; ohne diese Angabe bliebe es eine Frage
        $this->tenantInvoice('i1', '01.01.2025', '31.12.2025', '2000', '3500', ['working_prices' => [['from' => '01.01.2025', 'ct' => '31,20', 'basis' => 'gross', 'vat_pct' => '19 %']],
            'base_price' => '10,84', 'base_price_period' => 'month', 'base_price_basis' => 'net', 'base_price_vat_pct' => '19 %']);
        $d = $this->build();
        $p = self::items($d, 'prices', 'strom')[0];
        self::assertSame([31.2, false], [self::field($p, 'wp:2025-01-01')['value'], self::field($p, 'wp:2025-01-01')['check']]);
        self::assertSame([12.8996, false], [self::field($p, 'bp:2025-01-01')['value'], self::field($p, 'bp:2025-01-01')['check']], 'netto + 19 %, brutto vorbelegt');
        self::assertSame([], array_values(array_filter($d['missing'], fn($m) => str_contains($m['kind'], 'price'))), 'nichts fehlt');
    }

    public function testAPeriodWithoutAPriceStandsUnderStillMissing(): void
    {
        // Beginn auf die erste Rechnung vorgezogen, Preise erst ab der Bestätigung: der Abschnitt davor fehlt
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('i0', '01.04.2024', '31.03.2025', '200', '2000');
        $m = array_column(array_filter($this->build()['missing'], fn($x) => str_ends_with($x['kind'], '_gap')), null, 'kind');
        self::assertSame(['2024-04-01', '2025-03-31'], [$m['working_price_gap']['params']['from'] ?? null, $m['working_price_gap']['params']['to'] ?? null]);
        self::assertArrayHasKey('base_price_gap', $m);
    }

    public function testADocumentWithoutContributionHoldsNoMeter(): void
    {
        // eine verstrichene Zählerstandsanforderung mit eigener Nummer hält keinen Zähler; der erste Versorger
        // (Dokumente ohne Nummer) gehört an die einzige Messstelle mit Daten
        $this->doc('z', 'reading_request', ['utility' => 'strom', 'supplier' => 'Nordlicht Netz GmbH', 'requested_by' => '30.11.2024', 'meter_number' => 'STR-9999']);
        $this->doc('c0', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Westlicht Strom GmbH', 'supply_start' => '01.01.2023', 'working_price' => '30,00']);
        $this->doc('k0', 'cancellation', ['utility' => 'strom', 'supplier' => 'Westlicht Strom GmbH', 'supply_end' => '31.03.2025', 'cancel_kind' => 'switch']);
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3500');
        $d = $this->build();
        self::assertCount(1, self::items($d, 'meter', 'strom'), 'kein Zähler für die Anforderung allein');
        $c = array_column(array_map(fn($x) => ['p' => self::field($x, 'provider')['value'], 'mk' => $x['meter_key'], 'end' => self::field($x, 'end')['value'] ?? null],
            self::items($d, 'contract', 'strom')), null, 'p');
        self::assertSame(self::items($d, 'meter', 'strom')[0]['meter_key'], $c['Westlicht Strom GmbH']['mk'] ?? null, 'der erste Versorger am Zähler mit Daten');
        self::assertSame('2025-03-31', $c['Westlicht Strom GmbH']['end'], 'mit Ende');
    }

    public function testAConfirmationJoinedOverPricesUsesTheRunningContractOfItsSupplier(): void
    {
        // Lieferbeginn-Brief 01.01., Bestätigung ohne Beginn, Rechnung ab 15.01. mit ihrem Preis: ein Vertrag ab dem Brief
        $this->doc('l1', 'supply_start', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'supply_start' => '01.01.2025', 'meter_number' => 'STR-1001']);
        $this->doc('c1', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'tariff_name' => 'Südwind Fix 24', 'working_price' => '28,00']);
        $this->doc('i1', 'invoice', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'kind' => 'annual', 'period_from' => '15.01.2025',
            'period_to' => '31.12.2025', 'reading_old' => '5000', 'reading_old_date' => '15.01.2025', 'reading_new' => '7400', 'reading_new_date' => '31.12.2025',
            'total_amount' => '800,00', 'result_kind' => 'due', 'result_amount' => '20,00', 'meter_number' => 'STR-1001',
            'working_prices' => [['from' => '15.01.2025', 'ct' => '28,00']]]);
        $c = self::items($this->build(), 'contract', 'strom');
        self::assertCount(1, $c, 'kein Doppelvertrag');
        self::assertSame(['2025-01-01', 'Südwind Fix 24'], [self::field($c[0], 'start')['value'], self::field($c[0], 'tariff_name')['value']]);
        // zwei Bestätigungen mit gleichem Beginn (eine über den Preis der Rechnung): ein Vertrag
        $this->resetInbox();
        $this->doc('c2', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'tariff_name' => 'Südwind Basis', 'supply_start' => '15.01.2025',
            'working_price' => '30,00', 'meter_number' => 'STR-1001']);
        $this->doc('c3', 'contract_confirmation', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'tariff_name' => 'Südwind Fix 24', 'working_price' => '28,00']);
        $this->doc('i2', 'invoice', ['utility' => 'strom', 'supplier' => 'Südwind Strom AG', 'kind' => 'annual', 'period_from' => '15.01.2025',
            'period_to' => '31.12.2025', 'reading_old' => '5000', 'reading_old_date' => '15.01.2025', 'reading_new' => '7400', 'reading_new_date' => '31.12.2025',
            'total_amount' => '800,00', 'result_kind' => 'due', 'result_amount' => '20,00', 'meter_number' => 'STR-1001',
            'working_prices' => [['from' => '15.01.2025', 'ct' => '28,00']]]);
        self::assertCount(1, self::items($this->build(), 'contract', 'strom'), 'gleicher Beginn am selben Zähler: ein Vertrag');
    }

    public function testACorrectionIsRecognisedByFileNameTitleOrDocumentDate(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $total = fn() => self::field(self::items($this->build(), 'bill', 'strom')[0], 'total_amount');
        // „korr“ im Dateinamen
        $this->tenantInvoice('rg-2025', '01.04.2025', '31.12.2025', '2000', '3500');
        // (treffsicher, Nachbesserung 5: die Korrektur mit stimmigem Ergebnis — sonst markiert die Gegenrechnung auch den Gesamtbetrag)
        $this->tenantInvoice('rg-2025-korr', '01.04.2025', '31.12.2025', '2000', '3500', ['total_amount' => '810,00', 'result_amount' => '210,00']);
        self::assertSame([810.0, false], [$total()['value'], $total()['check']], 'Dateiname');
        // „Rechnungskorrektur“ im Titel (`document_title`)
        $this->resetInbox();
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('rg-a', '01.04.2025', '31.12.2025', '2000', '3500', ['document_title' => 'Rechnungskorrektur', 'total_amount' => '820,00', 'result_amount' => '220,00']);
        $this->tenantInvoice('rg-b', '01.04.2025', '31.12.2025', '2000', '3500', ['document_title' => 'Jahresabrechnung']);
        self::assertSame([820.0, false], [$total()['value'], $total()['check']], 'Titel');
        // ein späteres `document_date` gewinnt, auch wenn die Datei früher abgelegt wurde
        $this->resetInbox();
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('rg-x', '01.04.2025', '31.12.2025', '2000', '3500', ['document_date' => '20.02.2026', 'total_amount' => '830,00', 'result_amount' => '230,00']);
        $this->tenantInvoice('rg-y', '01.04.2025', '31.12.2025', '2000', '3500', ['document_date' => '15.01.2026']);
        self::assertSame([830.0, false], [$total()['value'], $total()['check']], 'Ausstellungsdatum');
    }

    public function testAnotherRateOnTheStartDayReplacesTheStartRow(): void
    {
        $this->doc('c1', 'contract_confirmation', ['utility' => 'gas', 'supplier' => 'Musterhausen-Energie GmbH', 'supply_start' => '01.01.2025',
            'working_price' => '10,70', 'vat_pct' => '7 %', 'meter_number' => 'GAS-9']);
        $this->doc('i1', 'invoice', ['utility' => 'gas', 'supplier' => 'Musterhausen-Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'reading_old' => '100', 'reading_old_date' => '31.12.2024', 'reading_new' => '1300', 'reading_new_date' => '31.12.2025',
            'total_amount' => '1300,00', 'result_kind' => 'credit', 'result_amount' => '20,00', 'meter_number' => 'GAS-9',
            'working_prices' => [['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '19 %']]]);
        $d = $this->build();
        $f = self::field(self::items($d, 'prices', 'gas')[0], 'wp:2025-01-01');
        self::assertSame([11.9, false, 'vat_changed'], [$f['value'], $f['check'], $f['basis'] ?? null], 'die Startzeile mit dem Satz der Rechnung');
        self::assertContains('c1.pdf', array_column($f['sources'], 'name'), 'die Bestätigung bleibt Beleg');
        self::assertNotContains('invoice_wp', array_column($d['findings'], 'kind'));
    }

    public function testDifferentIdentifiersPreferTheNewestDocument(): void
    {
        // nach Ausstellungsdatum: die Bestätigung kam nach der Rechnung (neue Kundennummer)
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025', 'customer_number' => 'K-3000', 'document_date' => '20.02.2026']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3500', ['customer_number' => 'K-2000', 'document_date' => '15.01.2026']);
        $f = self::field(self::items($this->build(), 'contract', 'strom')[0], 'customer_number');
        self::assertSame(['K-3000', false, 'newest'], [$f['value'], $f['check'], $f['hint'] ?? null], 'die Nummer aus dem jüngsten Dokument, vorgewählt');
        self::assertSame(['K-3000', 'K-2000'], array_column($f['options'], 'value'), 'die andere steht zur Wahl');
        // ohne Ausstellungsdatum zählt der Stichtag
        $this->resetInbox();
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025', 'customer_number' => 'K-1000']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3500', ['customer_number' => 'K-2000']);
        self::assertSame('K-2000', self::field(self::items($this->build(), 'contract', 'strom')[0], 'customer_number')['value']);
    }

    // ── Nachbesserung 4 ─────────────────────────────────────────────────

    public function testEstimatedReadingsAreTakenOverAsEstimated(): void
    {
        // „geschätzt“ an der Belegstelle (Evidence entscheidet am Zitat): die Ablesung trägt is_estimated
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3500', ['reading_new_estimated' => 'geschätzt']);
        $d = $this->build();
        $r = self::items($d, 'readings', 'strom')[0];
        self::assertSame([true, false], [!empty(self::field($r, 'r:2025-12-31')['is_estimated']), !empty(self::field($r, 'r:2025-04-01')['is_estimated'])]);
        $this->applyChecked($d);
        $byDate = array_column($this->readings->list('strom'), null, 'date');
        self::assertTrue(!empty($byDate['2025-12-31']['is_estimated']), 'geschätzt laut Rechnung');
        self::assertTrue(empty($byDate['2025-04-01']['is_estimated']), 'der Anfangsstand ist gemessen');
        // je Eintrag von `meter_readings`: v3.9.0 (F1034) geschätzt ist der Stand, hinter dem das Wort steht
        // (bis v3.8 galt es für beide Stände des Eintrags)
        $this->resetInbox();
        $this->doc('a', 'invoice', ['utility' => 'gas', 'supplier' => 'Musterhausen-Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'total_amount' => '700,00', 'result_kind' => 'due', 'result_amount' => '10,00', 'meter_number' => 'GAS-7',
            'meter_readings' => [['meter_number' => 'GAS-7', 'reading_old' => '100', 'reading_old_date' => '31.12.2024', 'reading_new' => '1300',
                'reading_new_date' => '31.12.2025', 'estimated' => 'geschätzt']]]);
        $r = self::items($this->build(), 'readings', 'gas')[0];
        self::assertSame([false, true], [!empty(self::field($r, 'r:2024-12-31')['is_estimated']), !empty(self::field($r, 'r:2025-12-31')['is_estimated'])]);
    }

    public function testASwapFromTheMeterReadingsIsPreselectedOnlyWhenEverythingFits(): void
    {
        $invoice = fn(string $old, string $oldEnd, string $newStart, string $newEnd, array $more = []) => $this->doc('a', 'invoice', $more + ['utility' => 'strom',
            'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'total_amount' => '700,00',
            'result_kind' => 'due', 'result_amount' => '10,00', 'meter_number' => 'STR-NEU', 'meter_readings' => [
                ['meter_number' => $old, 'reading_old' => '5000', 'reading_old_date' => '31.12.2024', 'reading_new' => $oldEnd, 'reading_new_date' => '15.05.2025'],
                ['meter_number' => 'STR-NEU', 'reading_old' => $newStart, 'reading_old_date' => '15.05.2025', 'reading_new' => $newEnd, 'reading_new_date' => '31.12.2025'],
            ]]);
        $swap = fn() => self::items($this->build(), 'meter_change', 'strom')[0] ?? null;
        $invoice('STR-ALT', '5800', '0', '1300');
        $s = $swap();
        self::assertNotNull($s);
        self::assertTrue($s['checked'], 'alles passt: vorbelegt');
        // der neue Zähler fällt nach dem Anfangsstand: nur ein Angebot
        $this->resetInbox();
        $invoice('STR-ALT', '5800', '900', '300');
        $s = $swap();
        self::assertSame([false, true], [$s['checked'], in_array('swap_readings', self::field($s, 'old_final')['reasons'], true)], 'fallender neuer Zähler');
        // ein bekannter Stand des alten Geräts liegt über dem Endstand
        $this->resetInbox();
        $this->doc('b', 'invoice', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2024',
            'period_to' => '31.12.2024', 'total_amount' => '600,00', 'result_kind' => 'due', 'result_amount' => '5,00', 'meter_number' => 'STR-ALT',
            'reading_old' => '4000', 'reading_old_date' => '31.12.2023', 'reading_new' => '6200', 'reading_new_date' => '31.12.2024']);
        $invoice('STR-ALT', '5800', '0', '1300');
        self::assertFalse($swap()['checked'], 'Endstand unter einem bekannten Stand');
        // der Zähler läuft im Bestand (über die Marktlokation) mit einem anderen Gerät: nur ein Angebot
        $this->resetInbox();
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['devices'][0]['serial'] = 'STR-ANDERS';
        $all[0]['malo_id'] = DraftFixture::MALO_B;
        $this->store->write('strom/meters.json', $all);
        $invoice('STR-ALT', '5800', '0', '1300', ['market_location' => DraftFixture::MALO_B]);
        $s = $swap();
        self::assertSame([false, true], [$s['checked'], in_array('swap_readings', self::field($s, 'old_final')['reasons'], true)], 'nicht das laufende Gerät');
    }

    public function testAConfirmedOrCorrectedNetPriceIsConvertedLikeThePrintedOne(): void
    {
        // nur die Rechnung nennt den Grundpreis: netto zzgl. 19 % — bestätigt (wie gedruckt) und korrigiert (in derselben Basis)
        $id = $this->tenantInvoice('i1', '01.01.2025', '31.12.2025', '2000', '3500',
            ['base_price' => '10,84', 'base_price_period' => 'month', 'base_price_basis' => 'net', 'base_price_vat_pct' => '19 %']);
        $bp = fn() => self::field(self::items($this->build(), 'prices', 'strom')[0], 'bp:2025-01-01');
        self::assertSame([12.8996, 19.0], [$bp()['value'], (float)($bp()['net']['vat_pct'] ?? 0)], 'umgerechnet, mit Basis am Feld');
        $edit = function (float $v) use ($id): void {
            $all = $this->store->read('inbox.json', []);
            foreach ($all as $i => $e) if ($e['id'] === $id) $all[$i]['edits']['base_price'] = $v;
            $this->store->write('inbox.json', $all);
        };
        $edit(10.84);
        self::assertSame(12.8996, $bp()['value'], 'bestätigt: der gedruckte Wert, umgerechnet');
        $edit(11.0);
        self::assertSame(13.09, $bp()['value'], 'korrigiert: in derselben Basis, umgerechnet');
    }

    public function testSeveralPeriodsOfOneMeterGiveOneSwapAndNoDoubleReading(): void
    {
        // gespeicherte Auslesung mit Schema 3: der neue Zähler in drei Ablesezeiträumen (drei Einträge derselben Nummer);
        // heute fasst die Belegprüfung sie zusammen (Evidence::mergeReadings) — gespeichert sind sie noch einzeln
        $rows = [
            ['meter_number' => 'STR-ALT', 'reading_old' => '5000', 'reading_old_date' => '31.12.2024', 'reading_new' => '5800', 'reading_new_date' => '15.05.2025'],
            ['meter_number' => 'STR-NEU', 'reading_old' => '0', 'reading_old_date' => '15.05.2025', 'reading_new' => '400', 'reading_new_date' => '31.08.2025'],
            ['meter_number' => 'STR-NEU', 'reading_old' => '400', 'reading_old_date' => '31.08.2025', 'reading_new' => '900', 'reading_new_date' => '31.10.2025'],
            ['meter_number' => 'STR-NEU', 'reading_old' => '900', 'reading_old_date' => '31.10.2025', 'reading_new' => '1300', 'reading_new_date' => '31.12.2025'],
        ];
        $id = $this->doc('a', 'invoice', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'total_amount' => '700,00', 'result_kind' => 'due', 'result_amount' => '10,00', 'meter_number' => 'STR-NEU',
            'meter_readings' => $rows]);
        $all = $this->store->read('inbox.json', []);
        $iso = fn(string $d) => implode('-', array_reverse(explode('.', $d)));
        foreach ($all as $n => $e) if ($e['id'] === $id) {
            $all[$n]['extraction']['fields']['meter_readings']['items'] = array_map(fn($i, $r) => [
                'value' => ['meter_number' => $r['meter_number'], 'reading_old' => (float)$r['reading_old'], 'reading_old_date' => $iso($r['reading_old_date']),
                    'reading_new' => (float)$r['reading_new'], 'reading_new_date' => $iso($r['reading_new_date'])],
                'quote' => 'meter_readings ' . ($i + 1) . ': ' . implode(' ', array_values($r)), 'page' => 1, 'status' => 'ok', 'reasons' => []], array_keys($rows), $rows);
        }
        $this->store->write('inbox.json', $all);
        self::assertCount(4, $this->store->read('inbox.json', [])[0]['extraction']['fields']['meter_readings']['items'], 'vier Einträge wie mit Schema 3');
        $d = $this->build();
        $ch = self::items($d, 'meter_change', 'strom');
        self::assertCount(1, $ch, 'ein Tausch');
        self::assertTrue($ch[0]['checked']);
        self::assertSame(['r:2024-12-31', 'r:2025-08-31', 'r:2025-10-31', 'r:2025-12-31'], array_column(self::items($d, 'readings', 'strom')[0]['fields'], 'name'),
            'Zwischenstände je einmal, am Tauschtag keine');
        self::assertNotContains('two_registers', array_column($d['missing'], 'kind'), 'kein Zweitarifzähler');
    }

    public function testACombinedEntryOfTheNewMeterGivesOneSwapAndNoDoubleReading(): void
    {
        // zusammengefasst (treffsicher, Nachbesserung 5): ein Eintrag je Nummer, frühester Anfangs- und spätester Endstand;
        // die Folgerechnung beginnt mit demselben Stand
        $this->doc('a', 'invoice', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'total_amount' => '700,00', 'result_kind' => 'due', 'result_amount' => '10,00', 'meter_number' => 'STR-NEU',
            'meter_readings' => [
                ['meter_number' => 'STR-ALT', 'reading_old' => '5000', 'reading_old_date' => '31.12.2024', 'reading_new' => '5800', 'reading_new_date' => '15.05.2025'],
                ['meter_number' => 'STR-NEU', 'reading_old' => '0', 'reading_old_date' => '15.05.2025', 'reading_new' => '1300', 'reading_new_date' => '31.12.2025'],
            ]]);
        $this->doc('b', 'invoice', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2026',
            'period_to' => '31.12.2026', 'total_amount' => '650,00', 'result_kind' => 'credit', 'result_amount' => '20,00', 'meter_number' => 'STR-NEU',
            'reading_old' => '1300', 'reading_old_date' => '31.12.2025', 'reading_new' => '3100', 'reading_new_date' => '31.12.2026']);
        $d = $this->build();
        $ch = self::items($d, 'meter_change', 'strom');
        self::assertCount(1, $ch, 'ein Tausch');
        self::assertTrue($ch[0]['checked']);
        self::assertSame(['r:2024-12-31', 'r:2025-12-31', 'r:2026-12-31'], array_column(self::items($d, 'readings', 'strom')[0]['fields'], 'name'),
            'der gemeinsame Stand der beiden Rechnungen einmal');
        self::assertNotContains('two_registers', array_column($d['missing'], 'kind'));
    }

    public function testATwoRateMeterGivesNoSwapAndNoDoubleReading(): void
    {
        // dieselbe Nummer zweimal (HT/NT): kein Tausch, keine Ablesung daraus, „Fehlt noch“
        $this->doc('a', 'invoice', ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'period_from' => '01.01.2025',
            'period_to' => '31.12.2025', 'total_amount' => '700,00', 'result_kind' => 'due', 'result_amount' => '10,00', 'meter_number' => 'STR-WP',
            'reading_old' => '5000', 'reading_old_date' => '31.12.2024', 'reading_new' => '5800', 'reading_new_date' => '31.12.2025',
            'meter_readings' => [
                ['meter_number' => 'STR-WP', 'reading_old' => '5000', 'reading_old_date' => '31.12.2024', 'reading_new' => '5800', 'reading_new_date' => '31.12.2025'],
                ['meter_number' => 'STR-WP', 'reading_old' => '2000', 'reading_old_date' => '31.12.2024', 'reading_new' => '2400', 'reading_new_date' => '31.12.2025'],
            ]]);
        $d = $this->build();
        self::assertSame([], self::items($d, 'meter_change', 'strom'));
        self::assertSame([], self::items($d, 'readings', 'strom'), 'keine Doppelablesung');
        $m = array_values(array_filter($d['missing'], fn($x) => $x['kind'] === 'two_registers'));
        self::assertSame(['a.pdf', 'STR-WP'], [$m[0]['params']['name'] ?? null, $m[0]['params']['meter'] ?? null]);
    }

    /** Posteingang leeren (für einen zweiten Stapel im selben Test). */
    private function resetInbox(): void
    {
        $this->store->write('inbox.json', []);
    }

    public function testInvoicePricesAloneStayToBeChecked(): void
    {
        $this->tenantInvoice('i1', '01.01.2025', '31.12.2025', '2000', '3500', ['working_prices' => [['from' => '01.01.2025', 'ct' => '31,20']]]);
        $this->tenantInvoice('i2', '01.01.2026', '31.12.2026', '3500', '5300', ['working_prices' => [['from' => '01.01.2025', 'ct' => '31,20']]]);
        $p = self::items($this->build(), 'prices', 'strom')[0];
        $f = self::field($p, 'wp:2025-01-01');
        self::assertTrue($f['check'], 'zwei Rechnungen mit demselben Preis bestätigen ihn nicht (netto, brutto)');
        self::assertContains('price_from_invoice', $f['reasons']);
    }

    // ── Bestand ─────────────────────────────────────────────────────────

    public function testTheEmptyDefaultMeterIsAdoptedNotDuplicated(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3800');
        $d = $this->build();
        $m = self::items($d, 'meter', 'strom')[0];
        self::assertSame(['adopt', 'm_strom_default'], [$m['action'], $m['meter_id']]);
        self::assertSame('2025-04-01', self::field($m, 'installed_on')['value'], 'Einbau = frühestes Dokument');
        $res = $this->applyChecked($d);
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        $meters = $this->meters->list('strom');
        self::assertCount(1, $meters);
        self::assertSame('STR-1001', $meters[0]['devices'][0]['serial']);
        self::assertSame('2025-04-01', $meters[0]['devices'][0]['installed_on']);
        $c = $this->contracts->list('strom');
        self::assertCount(1, $c);
        self::assertSame(['m_strom_default', 'NL-4401', '2025-04-01'], [$c[0]['meter_id'], $c[0]['contract_number'], $c[0]['start']]);
        self::assertCount(1, $c[0]['attachment_ids'], 'die Bestätigung hängt als Beleg am Vertrag');
        $b = $this->bills->list('strom');
        self::assertCount(1, $b);
        self::assertSame($c[0]['id'], $b[0]['contract_id']);
        self::assertNotEmpty($b[0]['special_payment_id'], 'Nachzahlung gebucht');
        self::assertCount(1, $b[0]['attachment_ids'], 'die Rechnung hängt an der Rechnung');
        self::assertSame(['done', 'done'], array_column($this->inbox->list(), 'status'), 'vollständig abgedeckt: übernommen');
        self::assertSame(2, $res['documents']['done']);
        self::assertSame('inbox', BackupService::reasonOf((string)$res['snapshot']), 'vorher gesichert');
        // der Rückweg: Zuordnung und Übernommenes am Dokument
        $e = $this->inbox->get(array_column($this->inbox->list(), 'id')[0]);
        self::assertSame('strom', $e['assignment']['utility']);
        self::assertNotEmpty($e['applied']);
    }

    public function testApplyingTwiceChangesNothing(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025', 'advance' => '55,00', 'market_location' => DraftFixture::MALO_B]);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3800', ['working_prices' => [['from' => '01.04.2025', 'ct' => '31,20']]]);
        $this->applyChecked($this->build());
        $count = fn() => [count($this->meters->list('strom')), count($this->contracts->list('strom')), count($this->readings->list('strom')),
            count($this->bills->list('strom')), json_encode($this->contracts->list('strom')[0]['working_prices']), json_encode($this->contracts->list('strom')[0]['special_payments'])];
        $before = $count();
        // die Dokumente noch einmal in den Entwurf (als hätte jemand sie wieder geöffnet)
        $all = $this->store->read('inbox.json', []);
        foreach ($all as $i => $e) $all[$i]['status'] = 'read';
        $this->store->write('inbox.json', $all);
        $d = $this->build();
        $open = array_values(array_filter($d['items'], fn($i) => $i['actionable']));
        self::assertSame([], array_map(fn($i) => $i['kind'] . ':' . $i['status'], $open), 'alles schon vorhanden');
        self::assertSame(0, $d['summary']['questions']);
        try {
            $this->applyChecked($d);
            self::fail('ohne Auswahl übernommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.inboxDraft.nothingSelected', $e->key);
        }
        self::assertSame($before, $count());
    }

    public function testExistingDataIsNotSilentlyOverwritten(): void
    {
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['devices'][0]['serial'] = 'STR-1001';
        $all[0]['devices'][0]['installed_on'] = '2024-01-01';
        $this->store->write('strom/meters.json', $all);
        $this->readings->create('strom', ['meter_id' => 'm_strom_default', 'date' => '2025-12-31', 'counter' => 3799]);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3800');
        $d = $this->build();
        $m = self::items($d, 'meter', 'strom')[0];
        self::assertSame('existing', $m['action'], 'über die Zählernummer gefunden');
        $r = self::items($d, 'readings', 'strom')[0];
        $f = self::field($r, 'r:2025-12-31');
        self::assertSame(['conflict', true, 3799.0], [$f['status'], $f['check'], $f['existing']]);
        $this->applyChecked($d);
        $byDate = array_column($this->readings->list('strom'), 'counter', 'date');
        self::assertSame(3799.0, (float)$byDate['2025-12-31'], 'ohne Bestätigung bleibt der Bestand');
        self::assertSame(2000.0, (float)$byDate['2025-04-01']);
        // bestätigt: jetzt gilt das Dokument
        $all = $this->store->read('inbox.json', []);
        foreach ($all as $i => $e) $all[$i]['status'] = 'read';
        $this->store->write('inbox.json', $all);
        $d2 = $this->build();
        $r2 = self::items($d2, 'readings', 'strom')[0];
        $this->draft->apply([$r2['id']], [$r2['id'] => ['r:2025-12-31']], [], null, self::TODAY);
        $byDate = array_column($this->readings->list('strom'), 'counter', 'date');
        self::assertSame(3800.0, (float)$byDate['2025-12-31']);
        self::assertCount(2, $this->readings->list('strom'), 'geändert, nicht verdoppelt');
    }

    public function testAStaleSelectionIsRejectedBeforeAnythingIsWritten(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $d = $this->build();
        $c = self::items($d, 'contract')[0];
        foreach ([[['d_000000000000'], []], [[$c['id']], [$c['id'] => ['unbekannt']]]] as [$ids, $confirm]) {
            try {
                $this->draft->apply($ids, $confirm, [], null, self::TODAY);
                self::fail('veraltete Auswahl übernommen');
            } catch (LocalizedException $e) {
                self::assertSame('errors.inbox.proposalStale', $e->key);
            }
        }
        self::assertSame([], $this->contracts->list('strom'));
        self::assertSame('read', $this->inbox->list()[0]['status']);
    }

    // ── Abgleich ────────────────────────────────────────────────────────

    public function testDifferentValuesForTheSameFactAreADecisionWithBothQuotes(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantConfirmation('c2', ['supply_start' => '01.04.2025', 'tariff_name' => 'Nordlicht Basis 24']);
        $d = $this->build();
        $c = self::items($d, 'contract')[0];
        $t = self::field($c, 'tariff_name');
        self::assertTrue($t['check']);
        self::assertSame(['differs'], $t['reasons']);
        self::assertSame(['Nordlicht Basis 12', 'Nordlicht Basis 24'], array_column($t['options'], 'value'));
        self::assertTrue($c['checked'], 'nur das Feld fragt, nicht der Vertrag');
        $this->draft->apply([$c['id']], [], [$c['id'] => ['tariff_name' => 1]], null, self::TODAY);
        self::assertSame('Nordlicht Basis 24', $this->contracts->list('strom')[0]['tariff_name'], 'die gewählte Fassung');
    }

    public function testAFallingReadingWithoutChangeSuggestsADeviceChange(): void
    {
        $base = ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'total_amount' => '500,00',
            'result_kind' => 'due', 'result_amount' => '5,00', 'contract_number' => 'NL-4401'];
        $this->doc('a', 'invoice', $base + ['period_from' => '01.01.2024', 'period_to' => '31.12.2024', 'reading_old' => '3000',
            'reading_old_date' => '31.12.2023', 'reading_new' => '5000', 'reading_new_date' => '31.12.2024']);
        $this->doc('b', 'invoice', $base + ['period_from' => '01.07.2025', 'period_to' => '31.12.2025', 'reading_old' => '40',
            'reading_old_date' => '30.06.2025', 'reading_new' => '900', 'reading_new_date' => '31.12.2025']);
        $d = $this->build();
        self::assertContains('falling', array_column($d['findings'], 'kind'));
        // der letzte hohe Stand liegt ein halbes Jahr davor: kein erschlossener Endstand, „Fehlt noch“
        self::assertSame([], self::items($d, 'meter_change', 'strom'));
        self::assertContains('old_final', array_column($d['missing'], 'kind'));
        // liegt er höchstens sieben Tage davor, ist der Wechsel ein Vorschlag (nicht vorbelegt)
        $this->resetInbox();
        $base = ['utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual', 'total_amount' => '500,00',
            'result_kind' => 'due', 'result_amount' => '5,00', 'contract_number' => 'NL-4401'];
        $this->doc('a', 'invoice', $base + ['period_from' => '01.01.2024', 'period_to' => '27.06.2025', 'reading_old' => '3000',
            'reading_old_date' => '31.12.2023', 'reading_new' => '5000', 'reading_new_date' => '27.06.2025']);
        $this->doc('b', 'invoice', $base + ['period_from' => '01.07.2025', 'period_to' => '31.12.2025', 'reading_old' => '40',
            'reading_old_date' => '30.06.2025', 'reading_new' => '900', 'reading_new_date' => '31.12.2025']);
        $ch = self::items($this->build(), 'meter_change', 'strom');
        self::assertCount(1, $ch);
        self::assertSame(['2025-06-30', true, false], [self::field($ch[0], 'date')['value'], $ch[0]['check'], $ch[0]['checked']],
            'nur ein Verdacht: nicht vorbelegt');
        self::assertContains('falling_reading', $ch[0]['reasons']);
    }

    public function testRemindersOnlyForWhatIsStillAhead(): void
    {
        $this->doc('z1', 'reading_request', ['utility' => 'strom', 'supplier' => 'Nordlicht Netz GmbH', 'requested_by' => '31.01.2026', 'meter_number' => 'STR-1001']);
        $this->doc('z0', 'reading_request', ['utility' => 'strom', 'supplier' => 'Nordlicht Netz GmbH', 'requested_by' => '31.01.2024', 'meter_number' => 'STR-1001']);
        $rem = self::items($this->build(), 'reminder');
        self::assertCount(1, $rem, 'verstrichene Termine aus alter Post entfallen');
        self::assertSame('2026-01-31', $rem[0]['params']['date']);
    }

    public function testTheSummaryCountsWhatTheDraftWouldCreate(): void
    {
        $this->tenantConfirmation('c1', ['supply_start' => '01.04.2025']);
        $this->tenantInvoice('i1', '01.04.2025', '31.12.2025', '2000', '3800', [], ['consumption']);
        $s = $this->build()['summary'];
        self::assertSame([2, 2, 1, 1, 2, 1, 1], [$s['documents'], $s['used'], $s['meters'], $s['contracts'], $s['readings'], $s['bills'], $s['questions']],
            json_encode($s));
    }
}
