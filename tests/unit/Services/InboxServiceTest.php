<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\HttpStatusException;
use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\DuplicateDocumentException;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\FakeInboxProvider;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (Posteingang) — ablegen, auslesen (Zwischenspeicher, Zustimmung),
 * Vorschau mit Abgleich, Übernehmen je Art über die Dienste der Handeingabe,
 * idempotent; Warteschlange, Prüfliste, Backup.
 */
#[CoversClass(InboxService::class)]
#[CoversClass(Proposals::class)]
#[CoversClass(Applier::class)]
final class InboxServiceTest extends ServiceTestCase
{
    private AttachmentService $att;
    private BillService $bills;
    private ReminderService $reminders;
    private ActivityService $activity;
    private FakeInboxProvider $local;
    private ProviderRegistry $registry;
    private InboxService $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $this->reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->activity = new ActivityService($this->store, $this->settings, $this->i18n);
        $this->local = InboxFixture::provider('local',
            ['Jahresabrechnung' => 'invoice', 'Vertragsbestätigung' => 'contract_confirmation', 'Preisanpassung' => 'price_change', 'Mahnung' => 'dunning'],
            ['invoice' => InboxFixture::invoiceAnswer(), 'contract_confirmation' => InboxFixture::confirmationAnswer(),
             'price_change' => InboxFixture::priceChangeAnswer()]);
        $this->registry = new ProviderRegistry($this->settings, $this->local);
        $this->inbox = $this->makeInbox();
    }

    private function makeInbox(): InboxService
    {
        $s = new InboxService($this->store, $this->i18n, $this->att, $this->registry,
            new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings),
            new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n));
        $s->attachActivity($this->activity);
        return $s;
    }

    /** Gas-Hauptzähler mit Nummer und laufendem Vertrag (Vertragskonto wie im Dokument). */
    private function gasHousehold(): array
    {
        $this->meters->update('gas', 'm_gas_default', []);
        $all = $this->store->read('gas/meters.json', []);
        $all[0]['devices'][0]['serial'] = InboxFixture::METER;
        $all[0]['devices'][0]['installed_on'] = '2020-01-01';
        $this->store->write('gas/meters.json', $all);
        $c = $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Gas Basis',
            'start' => '2024-01-01', 'contract_number' => 'VK-778899',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.0]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 140.0]]]);
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-12-31', 'counter' => 12345]);
        return $c;
    }

    private function read(string $tag, array $pages): array
    {
        $e = $this->inbox->upload(InboxFixture::pdf($tag), "$tag.pdf");
        $this->inbox->classify($e['id'], $pages);
        $this->inbox->extract($e['id'], $pages);
        return $this->inbox->preview($e['id']);
    }

    private static function byKind(array $proposals, string $kind): array
    {
        return array_values(array_filter($proposals, fn($p) => $p['kind'] === $kind));
    }

    // ── Ablegen ──────────────────────────────────────────────────────────

    public function testUploadStoresThePdfAsAnAttachmentAndRefusesDuplicates(): void
    {
        $e = $this->inbox->upload(InboxFixture::pdf('x'), 'Rechnung 2025.pdf');
        self::assertMatchesRegularExpression(InboxService::ID, $e['id']);
        self::assertSame('new', $e['status']);
        self::assertSame(hash('sha256', InboxFixture::pdf('x')), $e['sha256']);
        $a = $this->att->get($e['attachment_id']);
        self::assertSame('inbox_pdf', $a['kind']);
        self::assertSame(['type' => 'inbox', 'id' => $e['id']], $a['ref'], 'das PDF gehört dem Posteingang');
        try {
            $this->inbox->upload(InboxFixture::pdf('x'), 'noch einmal.pdf');
            self::fail('Doppel angenommen');
        } catch (DuplicateDocumentException $d) {
            self::assertSame($e['id'], $d->existing['id']);
            self::assertSame('Rechnung 2025.pdf', $d->existing['name']);
        }
        self::assertCount(1, $this->inbox->list());
        $this->expectException(LocalizedException::class);
        $this->inbox->upload("\xFF\xD8\xFF" . 'kein pdf', 'foto.jpg');
    }

    public function testDeletingRemovesThePdfOnlyWhileItHangsAtTheInbox(): void
    {
        $e = $this->inbox->upload(InboxFixture::pdf('del'), 'a.pdf');
        $this->inbox->delete($e['id']);
        self::assertNull($this->att->get($e['attachment_id']));
        self::assertSame([], $this->inbox->list());

        // als Beleg an einer Rechnung bleibt es
        $this->gasHousehold();
        $p = $this->read('inv', InboxFixture::invoicePages());
        $bill = self::byKind($p['proposals'], 'bill')[0];
        $this->inbox->apply($p['entry']['id'], [$bill['id']]);
        $this->inbox->delete($p['entry']['id']);
        $kept = $this->att->get($p['entry']['attachment_id']);
        self::assertSame('bill', $kept['ref']['type'] ?? null);
    }

    // ── Auslesen ─────────────────────────────────────────────────────────

    public function testReadingRecognisesTheTypeThenTheFieldsAndChecksTheQuotes(): void
    {
        $this->gasHousehold();
        $p = $this->read('inv', InboxFixture::invoicePages());
        $e = $p['entry'];
        self::assertSame('read', $e['status']);
        self::assertSame('invoice', $e['type']);
        self::assertSame('local', $e['extraction']['provider']);
        self::assertSame('fake-1', $e['extraction']['model']);
        self::assertSame(5, $e['extraction']['schema_version'], 'v3.7.0 (treffsicher): 2 Netto und Steuersatz je Preis, 3 Briefdatum, Titel, Stände je Zähler, 4 Beschreibungen (Nummer, Zeiträume, Schätzung); v3.9.0: 5 Wasserpreise, Abschlagstermine, Bestandteile, Zählwerk je Stand');
        self::assertSame([3], $e['extraction']['text']['dropped'], 'AGB-Seite nicht gesendet');
        self::assertStringNotContainsString('Geltungsbereich', $this->local->lastText);
        self::assertSame('invoice', $this->local->lastSchema['type']);
        self::assertEquals(13580, $e['extraction']['fields']['reading_new']['value']);
        // markiert: nur die Preise (Pflichtprüfung)
        self::assertSame(2, $e['extraction']['flags']);
        self::assertSame(['utility' => 'gas', 'meter_id' => 'm_gas_default'], array_intersect_key($p['assignment'], ['utility' => 1, 'meter_id' => 1]));
        self::assertNotNull($p['assignment']['contract_id'], 'Vertrag über das Vertragskonto vorbelegt');
        self::assertContains('meter_number', $p['suggested']['by']);
    }

    public function testTheCacheIsKeyedByProviderModelAndSchema(): void
    {
        $pages = InboxFixture::invoicePages();
        $e = $this->inbox->upload(InboxFixture::pdf('c'), 'c.pdf');
        $this->inbox->classify($e['id'], $pages);
        $this->inbox->extract($e['id'], $pages);
        $this->inbox->classify($e['id'], $pages);
        $this->inbox->extract($e['id'], $pages);
        self::assertSame(1, $this->local->classifyCalls, 'zweites Mal aus dem Zwischenspeicher');
        self::assertSame(1, $this->local->extractCalls);
        self::assertTrue($this->inbox->get($e['id'])['extraction']['cached']);
        // anderes Modell → neu
        $other = InboxFixture::provider('local', ['Jahresabrechnung' => 'invoice'], ['invoice' => InboxFixture::invoiceAnswer()], 'fake-2');
        $this->registry = new ProviderRegistry($this->settings, $other);
        $this->inbox = $this->makeInbox();
        $this->inbox->extract($e['id'], $pages);
        self::assertSame(1, $other->extractCalls);
        self::assertCount(2, $this->inbox->get($e['id'])['cache']);
        // fresh erzwingt
        $this->inbox->extract($e['id'], $pages, false, true);
        self::assertSame(2, $other->extractCalls);
    }

    public function testRecognizeOnlyTypesAreFiledWithoutFields(): void
    {
        $e = $this->inbox->upload(InboxFixture::pdf('m'), 'mahnung.pdf');
        $pages = ['Stadtwerke Musterstadt GmbH Mahnung: Bitte zahlen Sie den offenen Betrag bis zum 15.11.2026.'];
        $x = $this->inbox->extract($e['id'], $pages);
        self::assertSame('dunning', $x['type']);
        self::assertSame('filed', $x['status']);
        self::assertSame(0, $this->local->extractCalls);
        self::assertSame([], $this->inbox->preview($e['id'])['proposals']);
    }

    public function testWithoutTextLayerNothingIsSent(): void
    {
        $e = $this->inbox->upload(InboxFixture::pdf('scan'), 'scan.pdf');
        try {
            $this->inbox->classify($e['id'], ['', ' ']);
            self::fail('ohne Text ausgelesen');
        } catch (LocalizedException $x) {
            self::assertSame('errors.inbox.noText', $x->key);
        }
        self::assertSame(0, $this->local->classifyCalls);
        self::assertSame('failed', $this->inbox->get($e['id'])['status']);
        self::assertSame('errors.inbox.noText', $this->inbox->get($e['id'])['error']['key']);
    }

    public function testAnotherProviderNeedsConsentAndAnUnknownOneNeverFallsBack(): void
    {
        // ein Cloud-Anschluss ist erst wählbar, wenn das Auslesen in der Cloud für ihn eingeschaltet ist und ein Schlüssel da ist
        $cloud = InboxFixture::provider('openai', ['Jahresabrechnung' => 'invoice'], ['invoice' => InboxFixture::invoiceAnswer()]);
        $this->registry->register($cloud);
        $this->settings->set(['inbox_cloud_enabled' => true, 'inbox_cloud_provider' => 'openai']);
        $this->store->write(\Energietracker\Services\Inbox\CloudConfig::KEY_FILE, ['openai' => 'sk-test-only-0000']);
        $this->settings->set(['inbox_provider' => 'openai']);
        $e = $this->inbox->upload(InboxFixture::pdf('cl'), 'cl.pdf');
        try {
            $this->inbox->classify($e['id'], InboxFixture::invoicePages());
            self::fail('ohne Zustimmung gesendet');
        } catch (HttpStatusException $x) {
            self::assertSame(409, $x->status);
            self::assertSame('errors.inbox.consentRequired', $x->key);
            self::assertSame('Testdienst (openai)', $x->params['provider']);
        }
        self::assertSame(0, $cloud->classifyCalls);
        self::assertSame(0, $this->local->classifyCalls, 'kein stiller Rückfall auf lokal');
        $this->inbox->extract($e['id'], InboxFixture::invoicePages(), true);
        self::assertSame(1, $cloud->extractCalls);
        self::assertSame('openai', $this->inbox->get($e['id'])['extraction']['provider']);
        $info = $this->inbox->providerInfo();
        self::assertFalse($info['local']);
        self::assertSame('Testdienst (openai)', $info['label']);

        // gewählt, aber nicht angemeldet (etwa ein entfernter Anschluss): nie ein stiller Rückfall
        $this->settings->set(['inbox_cloud_provider' => 'anthropic']);
        $this->store->write(\Energietracker\Services\Inbox\CloudConfig::KEY_FILE, ['anthropic' => 'sk-test-only-0000']);
        $this->settings->set(['inbox_provider' => 'anthropic']);
        try {
            $this->inbox->classify($e['id'], InboxFixture::invoicePages(), true);
            self::fail('unbekannter Anbieter');
        } catch (LocalizedException $x) {
            self::assertSame('errors.inbox.providerUnknown', $x->key);
        }
    }

    // ── Vorschau und Übernehmen ──────────────────────────────────────────

    public function testInvoiceBecomesReadingBillBookingAdvanceAndGasFactor(): void
    {
        $c = $this->gasHousehold();
        $p = $this->read('inv', InboxFixture::invoicePages());
        $kinds = array_count_values(array_column($p['proposals'], 'kind'));
        self::assertSame(2, $kinds['reading'] ?? 0, 'neuer und alter Stand');
        foreach (['bill', 'bill_book', 'contract_advance', 'gas_factor', 'contract_price', 'contract_base_price', 'meter_ids', 'contract_ids'] as $k) {
            self::assertArrayHasKey($k, $kinds, $k);
        }
        $old = array_values(array_filter(self::byKind($p['proposals'], 'reading'), fn($x) => $x['data']['date'] === '2024-12-31'))[0];
        self::assertSame('exists', $old['status'], 'der alte Stand ist schon erfasst');
        $price = self::byKind($p['proposals'], 'contract_price')[0];
        self::assertTrue($price['check'], 'Preis aus der Rechnung: prüfen');
        self::assertFalse($price['checked'], 'nicht vorbelegt');
        $bill = self::byKind($p['proposals'], 'bill')[0];
        self::assertTrue($bill['checked']);
        self::assertEquals(42.7, $bill['data']['invoice']['result_eur'], 'Nachzahlung positiv');
        self::assertEquals(13216, $bill['data']['invoice']['energy_kwh']);

        $ids = array_column(array_filter($p['proposals'], fn($x) => $x['checked']), 'id');
        $res = $this->inbox->apply($p['entry']['id'], $ids);
        self::assertSame([], $res['failed']);
        self::assertSame('done', $res['entry']['status']);

        $r = array_values(array_filter($this->readings->list('gas', 'm_gas_default'), fn($x) => $x['date'] === '2025-12-31'))[0];
        self::assertSame(13580.0, (float)$r['counter']);
        self::assertSame('inbox', $r['source']);
        $b = $this->bills->list('gas', 'm_gas_default')[0];
        self::assertSame(['2025-01-01', '2025-12-31'], [$b['period_from'], $b['period_to']]);
        self::assertSame([$p['entry']['attachment_id']], $b['attachment_ids'], 'das Dokument hängt als Beleg an der Rechnung');
        self::assertNotEmpty($b['special_payment_id'], 'gebucht als Sonderzahlung');
        $ct = $this->contracts->get('gas', $c['id']);
        self::assertSame(145.0, (float)array_values(array_filter($ct['advance_payments'], fn($a) => $a['from'] === '2026-02-01'))[0]['amount_eur']);
        self::assertSame('K-100200', $ct['customer_number'], 'Kundennummer ergänzt');
        $f = array_values(array_filter($this->settings->get('gas_conversion_factors'), fn($x) => ($x['from'] ?? null) === '2025-01-01'))[0];
        self::assertEqualsWithDelta(0.9512 * 11.25, $f['kwh_per_m3'], 1e-4, 'Vorschlag für die Stichtag-Liste übernommen');
        self::assertSame(InboxFixture::MALO, $this->meters->get('gas', 'm_gas_default')['malo_id']);
        // die Preiszeile war nicht angehakt
        self::assertCount(1, $ct['working_prices']);
        // Verlauf: „aus Dokument übernommen“
        $act = array_values(array_filter($this->activity->all(), fn($a) => ($a['subject']['kind'] ?? '') === 'inbox'));
        self::assertCount(1, $act);
        self::assertSame('updated', $act[0]['action']);
        self::assertSame('activity.inbox.applied', $act[0]['title_key']);
        self::assertSame($p['entry']['id'], $act[0]['ref']['inbox_id']);
    }

    public function testTheNewAdvanceGoesToTheContractThatRunsThen(): void
    {
        $c = $this->gasHousehold();
        $this->contracts->update('gas', $c['id'], ['end' => '2025-12-31']);
        $next = $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Gas Basis 2026',
            'start' => '2026-01-01', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 10.5]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 140.0]]]);
        $p = $this->read('inv', InboxFixture::invoicePages());
        self::assertSame($c['id'], $p['assignment']['contract_id'], 'die Rechnung gehört zum Vertrag ihres Zeitraums');
        $adv = self::byKind($p['proposals'], 'contract_advance')[0];
        self::assertSame($next['id'], $adv['data']['contract_id'], 'der neue Abschlag gehört zum Folgevertrag');
        // die Buchung folgt der Rechnung: dieselben Quellen, dieselbe Marke
        $bill = self::byKind($p['proposals'], 'bill')[0];
        self::assertSame($bill['sources'], self::byKind($p['proposals'], 'bill_book')[0]['sources']);
    }

    public function testContractNumbersAreShortTextAndReceiptsMustExist(): void
    {
        $e = $this->inbox->upload(InboxFixture::pdf('ct'), 'ct.pdf');
        $c = $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Stadtwerke Musterstadt', 'start' => '2024-01-01',
            'contract_number' => '  VK-1  ', 'customer_number' => str_repeat('9', 80), 'attachment_ids' => [$e['attachment_id'], $e['attachment_id']],
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.0]]]);
        self::assertSame('VK-1', $c['contract_number'], 'ohne Leerraum am Rand');
        self::assertSame(64, mb_strlen($c['customer_number']), 'höchstens 64 Zeichen');
        self::assertSame([$e['attachment_id']], $c['attachment_ids'], 'jeder Beleg einmal');
        $u = $this->contracts->update('gas', $c['id'], ['contract_number' => '', 'attachment_ids' => []]);
        self::assertArrayNotHasKey('contract_number', $u, 'leer entfernt das Feld');
        self::assertArrayNotHasKey('attachment_ids', $u);
        try {
            $this->contracts->update('gas', $c['id'], ['attachment_ids' => ['att_0000000000000000']]);
            self::fail('unbekannter Beleg angenommen');
        } catch (LocalizedException $x) {
            self::assertSame('errors.attachment.notFound', $x->key);
        }
    }

    public function testApplyingTwiceChangesNothing(): void
    {
        $this->gasHousehold();
        $p = $this->read('inv', InboxFixture::invoicePages());
        $ids = array_column(array_filter($p['proposals'], fn($x) => $x['checked']), 'id');
        $this->inbox->apply($p['entry']['id'], $ids);
        $before = [$this->readings->list('gas'), $this->bills->list('gas'), $this->contracts->list('gas'), $this->settings->get('gas_conversion_factors')];
        $again = $this->inbox->preview($p['entry']['id']);
        foreach ($again['proposals'] as $x) {
            if (in_array($x['kind'], ['contract_price', 'contract_base_price'], true)) continue;   // nicht übernommen
            self::assertSame('exists', $x['status'], $x['kind'] . ' erkennt Vorhandenes');
        }
        $res = $this->inbox->apply($p['entry']['id'], array_column(array_filter($again['proposals'], fn($x) => $x['status'] === 'exists'), 'id'));
        self::assertSame([], $res['applied']);
        self::assertNotEmpty($res['skipped']);
        self::assertSame($before, [$this->readings->list('gas'), $this->bills->list('gas'), $this->contracts->list('gas'), $this->settings->get('gas_conversion_factors')]);
    }

    public function testStaleProposalsAreRejected(): void
    {
        $this->gasHousehold();
        $p = $this->read('inv', InboxFixture::invoicePages());
        $this->inbox->update($p['entry']['id'], ['edits' => ['reading_new' => '13.590']]);
        $this->expectException(LocalizedException::class);
        $this->inbox->apply($p['entry']['id'], [self::byKind($p['proposals'], 'reading')[0]['id']]);
    }

    public function testCorrectionsReplaceTheValueAndClearTheFlag(): void
    {
        $this->gasHousehold();
        $p = $this->read('inv', InboxFixture::invoicePages());
        $price = self::byKind($p['proposals'], 'contract_price')[0];
        self::assertTrue($price['check']);
        $q = $this->inbox->update($p['entry']['id'], ['edits' => ['reading_new' => '13.590']]);
        self::assertEquals(13590, $q['edits']['reading_new']);
        $new = array_values(array_filter(self::byKind($this->inbox->preview($p['entry']['id'])['proposals'], 'reading'), fn($x) => $x['data']['date'] === '2025-12-31'))[0];
        self::assertEquals(13590, $new['data']['counter']);
        try {
            $this->inbox->update($p['entry']['id'], ['edits' => ['reading_new' => 'viel']]);
            self::fail('unlesbare Korrektur');
        } catch (LocalizedException $x) {
            self::assertSame('errors.inbox.editInvalid', $x->key);
        }
        // eine Korrektur des Datums
        $this->inbox->update($p['entry']['id'], ['edits' => ['reading_new_date' => '30.12.2025']]);
        self::assertSame('2025-12-30', $this->inbox->get($p['entry']['id'])['edits']['reading_new_date']);
    }

    public function testAConflictingReadingIsNotPreselectedAndOverwritesOnlyWhenChecked(): void
    {
        $this->gasHousehold();
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2025-12-31', 'counter' => 13570]);
        $p = $this->read('inv', InboxFixture::invoicePages());
        $new = array_values(array_filter(self::byKind($p['proposals'], 'reading'), fn($x) => $x['data']['date'] === '2025-12-31'))[0];
        self::assertSame('conflict', $new['status']);
        self::assertSame(13570.0, $new['detail']['counter']);
        self::assertFalse($new['checked']);
        $this->inbox->apply($p['entry']['id'], [$new['id']]);
        $all = array_values(array_filter($this->readings->list('gas'), fn($x) => $x['date'] === '2025-12-31'));
        self::assertCount(1, $all, 'überschrieben, nicht verdoppelt');
        self::assertSame(13580.0, (float)$all[0]['counter']);
    }

    public function testContractConfirmationCreatesTheContractWithPricesAndDocument(): void
    {
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['devices'][0]['serial'] = 'TEST-STROM-9';
        $this->store->write('strom/meters.json', $all);
        $prev = $this->contracts->create('strom', ['meter_id' => 'm_strom_default', 'provider' => 'Altversorger', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 35.0]]]);
        $p = $this->read('conf', InboxFixture::confirmationPages());
        self::assertSame('strom', $p['assignment']['utility']);
        self::assertSame('m_strom_default', $p['assignment']['meter_id']);
        self::assertNull($p['assignment']['contract_id'], 'eine Vertragsbestätigung bringt einen neuen Vertrag');
        $c = self::byKind($p['proposals'], 'contract_create')[0];
        self::assertSame('overlap', $c['status'], 'der alte Vertrag läuft ohne Ende');
        self::assertTrue($c['checked']);
        $end = self::byKind($p['proposals'], 'contract_end')[0];
        self::assertSame('2026-02-28', $end['data']['end']);
        // v3.7.0 (treffsicher, Nachbesserung nach dem Nutzentest) — ein Wechsel am selben Zähler:
        // der Vorgänger endet vorbelegt am Vortag (bis dahin nur als Angebot, die Verträge überschnitten sich)
        self::assertTrue($end['checked'], 'Wechsel: Vorgänger endet vorbelegt');
        $this->inbox->apply($p['entry']['id'], [$c['id'], $end['id']]);
        $new = array_values(array_filter($this->contracts->list('strom'), fn($x) => $x['start'] === '2026-03-01'))[0];
        self::assertSame('Musterenergie Nord AG', $new['provider']);
        self::assertSame('Ökostrom Fix 24', $new['tariff_name']);
        self::assertSame(28.45, (float)$new['working_prices'][0]['ct_per_kwh']);
        self::assertEqualsWithDelta(12.95, (float)$new['base_prices'][0]['eur_per_month'], 1e-9, 'Jahresgrundpreis je Monat');
        self::assertSame(82.0, (float)$new['advance_payments'][0]['amount_eur']);
        self::assertSame('2028-02-29', $new['min_term_end'], '24 Monate ab dem 01.03.2026');
        self::assertSame(1, $new['notice_period_months']);
        self::assertSame('2028-02-28', $new['price_guarantee_until']);
        self::assertEquals(60, $new['signup_bonus_eur']);
        self::assertSame('ST-55-1234', $new['contract_number']);
        self::assertSame([$p['entry']['attachment_id']], $new['attachment_ids'], 'Beleg am Vertrag');
        self::assertSame('contract', $this->att->get($p['entry']['attachment_id'])['ref']['type']);
        self::assertSame('2026-02-28', $this->contracts->get('strom', $prev['id'])['end']);
        self::assertFalse($this->contracts->get('strom', $prev['id'])['auto_renews']);
    }

    public function testPriceChangeAddsDatedPricesAndAReminder(): void
    {
        $c = $this->gasHousehold();
        $p = $this->read('pc', InboxFixture::priceChangePages());
        self::assertSame($c['id'], $p['assignment']['contract_id']);
        $wp = self::byKind($p['proposals'], 'contract_price')[0];
        self::assertSame(['from' => '2026-04-01', 'value' => 12.8], array_intersect_key($wp['data'], ['from' => 1, 'value' => 1]));
        self::assertTrue($wp['checked'], 'Preis aus einer Preisänderung ist verlässlich');
        $ids = array_column(array_filter($p['proposals'], fn($x) => $x['checked']), 'id');
        $this->inbox->apply($p['entry']['id'], $ids);
        $ct = $this->contracts->get('gas', $c['id']);
        self::assertSame(['2024-01-01', '2026-04-01'], array_column($ct['working_prices'], 'from'));
        self::assertSame(13.1, (float)$ct['base_prices'][1]['eur_per_month']);
        $rem = array_values(array_filter($this->reminders->list(), fn($r) => $r['next_due'] === '2026-03-31'));
        self::assertCount(1, $rem, 'Sonderkündigungsrecht als Termin');
        self::assertContains($p['entry']['attachment_id'], $ct['attachment_ids']);
        // wieder: alles vorhanden, auch der Termin
        foreach ($this->inbox->preview($p['entry']['id'])['proposals'] as $x) self::assertSame('exists', $x['status'], $x['kind']);
    }

    public function testCancellationEndsTheContract(): void
    {
        $c = $this->gasHousehold();
        $pages = ['Stadtwerke Musterstadt GmbH: Wir bestätigen Ihre Kündigung. Lieferende Erdgas ist der 30.06.2026. Vertragskonto: VK-778899'];
        $this->local = InboxFixture::provider('local', ['Kündigung' => 'cancellation'], ['cancellation' => [
            'utility' => ['value' => 'gas', 'quote' => 'Lieferende Erdgas', 'page' => 1],
            'supply_end' => ['value' => '30.06.2026', 'quote' => 'Lieferende Erdgas ist der 30.06.2026', 'page' => 1],
            'cancel_kind' => ['value' => 'customer', 'quote' => 'Wir bestätigen Ihre Kündigung', 'page' => 1],
            'contract_number' => ['value' => 'VK-778899', 'quote' => 'Vertragskonto: VK-778899', 'page' => 1],
        ]]);
        $this->registry = new ProviderRegistry($this->settings, $this->local);
        $this->inbox = $this->makeInbox();
        $p = $this->read('k', $pages);
        $end = self::byKind($p['proposals'], 'contract_end')[0];
        self::assertTrue($end['checked']);
        $this->inbox->apply($p['entry']['id'], [$end['id']]);
        self::assertSame('2026-06-30', $this->contracts->get('gas', $c['id'])['end']);
        self::assertFalse($this->contracts->get('gas', $c['id'])['auto_renews']);
    }

    public function testMeterChangeIsOfferedOnlyWithReview(): void
    {
        $this->gasHousehold();
        $pages = ['Zählerwechsel Erdgas am 15.05.2026. Ausbau Zähler TEST-4711-01 mit Endstand 14.002,3 m³. Einbau Zähler TEST-0815-02 mit Anfangsstand 0,0 m³.'];
        $q = $pages[0];
        $this->local = InboxFixture::provider('local', ['Zählerwechsel' => 'meter_change'], ['meter_change' => [
            'utility' => ['value' => 'gas', 'quote' => 'Zählerwechsel Erdgas', 'page' => 1],
            'change_date' => ['value' => '15.05.2026', 'quote' => 'Zählerwechsel Erdgas am 15.05.2026', 'page' => 1],
            'old_meter_number' => ['value' => 'TEST-4711-01', 'quote' => 'Ausbau Zähler TEST-4711-01', 'page' => 1],
            'old_reading' => ['value' => '14.002,3', 'quote' => 'Endstand 14.002,3 m³', 'page' => 1],
            'new_meter_number' => ['value' => 'TEST-0815-02', 'quote' => 'Einbau Zähler TEST-0815-02', 'page' => 1],
            'new_reading' => ['value' => '0,0', 'quote' => 'Anfangsstand 0,0 m³', 'page' => 1],
        ]]);
        $this->registry = new ProviderRegistry($this->settings, $this->local);
        $this->inbox = $this->makeInbox();
        $p = $this->read('mc', $pages);
        self::assertTrue($p['review_all']);
        $mc = self::byKind($p['proposals'], 'meter_change')[0];
        self::assertTrue($mc['check']);
        self::assertFalse($mc['checked'], 'Pflichtprüfung: nicht vorbelegt');
        // bestätigt der Nutzer die Felder, ist es vorbelegt
        $edits = [];
        foreach (['change_date', 'old_reading', 'new_reading', 'new_meter_number', 'old_meter_number'] as $f) $edits[$f] = $p['entry']['extraction']['fields'][$f]['value'];
        $this->inbox->update($p['entry']['id'], ['edits' => $edits]);
        $mc = self::byKind($this->inbox->preview($p['entry']['id'])['proposals'], 'meter_change')[0];
        self::assertTrue($mc['checked']);
        $this->inbox->apply($p['entry']['id'], [$mc['id']]);
        $m = $this->meters->get('gas', 'm_gas_default');
        self::assertCount(2, $m['devices']);
        self::assertSame('2026-05-15', $m['devices'][0]['removed_on']);
        self::assertSame(14002.3, (float)$m['devices'][0]['final_counter']);
        self::assertSame('TEST-0815-02', $m['devices'][1]['serial']);
        self::assertSame('exists', self::byKind($this->inbox->preview($p['entry']['id'])['proposals'], 'meter_change')[0]['status']);
    }

    public function testReadingRequestBecomesAReminder(): void
    {
        $pages = ['Bitte teilen Sie uns Ihren Zählerstand Strom bis zum 10.01.2027 mit. Zählernummer: TEST-STROM-9'];
        $this->local = InboxFixture::provider('local', ['Zählerstand' => 'reading_request'], ['reading_request' => [
            'utility' => ['value' => 'strom', 'quote' => 'Zählerstand Strom', 'page' => 1],
            'requested_by' => ['value' => '10.01.2027', 'quote' => 'bis zum 10.01.2027', 'page' => 1],
            'meter_number' => ['value' => 'TEST-STROM-9', 'quote' => 'Zählernummer: TEST-STROM-9', 'page' => 1],
        ]]);
        $this->registry = new ProviderRegistry($this->settings, $this->local);
        $this->inbox = $this->makeInbox();
        $p = $this->read('rr', $pages);
        $r = self::byKind($p['proposals'], 'reminder')[0];
        self::assertFalse($r['checked']);
        $this->inbox->apply($p['entry']['id'], [$r['id']]);
        $rem = array_values(array_filter($this->reminders->list(), fn($x) => $x['next_due'] === '2027-01-10'));
        self::assertCount(1, $rem);
        self::assertSame('exists', self::byKind($this->inbox->preview($p['entry']['id'])['proposals'], 'reminder')[0]['status']);
    }

    // ── Warteschlange, Prüfliste, Backup ─────────────────────────────────

    public function testTheQueueResumesAndCanBeCancelled(): void
    {
        $a = $this->inbox->upload(InboxFixture::pdf('q1'), 'q1.pdf');
        $b = $this->inbox->upload(InboxFixture::pdf('q2'), 'q2.pdf');
        $s = $this->inbox->queue();
        self::assertSame(2, $s['added']);
        self::assertSame($a['id'], $s['next'], 'in der Reihenfolge des Ablegens');
        $this->inbox->extract($a['id'], InboxFixture::invoicePages());
        self::assertSame($b['id'], $this->inbox->queueState()['next'], 'fortsetzen beim nächsten');
        self::assertSame(1, $this->inbox->queueState()['queued']);
        $c = $this->inbox->cancelQueue();
        self::assertSame(1, $c['cancelled']);
        self::assertSame('new', $this->inbox->get($b['id'])['status']);
        self::assertSame('read', $this->inbox->get($a['id'])['status']);
        self::assertSame(0, $this->inbox->queue([$a['id']])['added'], 'Ausgelesenes nicht noch einmal');
    }

    public function testTheReviewListHoldsOnlyFlaggedFields(): void
    {
        $this->gasHousehold();
        $p = $this->read('inv', InboxFixture::invoicePages());
        $list = $this->inbox->review();
        self::assertEqualsCanonicalizing(['base_price', 'working_prices'], array_column($list, 'field'), 'nur die markierten Preise');
        self::assertSame($p['entry']['id'], $list[0]['entry_id']);
        $this->inbox->update($p['entry']['id'], ['edits' => ['base_price' => '12,50']]);
        self::assertCount(1, $this->inbox->review(), 'bestätigt = nicht mehr in der Liste');
    }

    public function testTheInboxIsPartOfTheBackupAndComesBack(): void
    {
        $this->gasHousehold();
        $p = $this->read('inv', InboxFixture::invoicePages());
        $backups = new BackupService($this->store, $this->i18n);
        $export = $backups->export();
        self::assertArrayHasKey('inbox', $export);
        self::assertSame($p['entry']['id'], $export['inbox'][0]['id']);
        self::assertArrayHasKey($p['entry']['attachment_id'], $export['attachment_files'], 'das PDF reist mit');
        $this->store->write('inbox.json', []);
        @unlink($this->store->path('attachments/' . $p['entry']['attachment_id'] . '.pdf'));
        $backups->import(json_decode(json_encode($export), true), false, true);
        $back = $this->inbox->get($p['entry']['id']);
        self::assertSame('read', $back['status']);
        self::assertSame($p['entry']['extraction']['fields']['reading_new'], $back['extraction']['fields']['reading_new']);
        self::assertFileExists($this->att->path($p['entry']['attachment_id']));
        // kaputte Einträge lehnt der Import ab
        $bad = $export;
        $bad['inbox'][0]['status'] = 'irgendwas';
        $this->expectException(\Energietracker\Services\BackupInvalidException::class);
        $backups->import($bad, true);
    }

    public function testAnAttachmentListedInTheInboxIsNeverSweptAway(): void
    {
        $e = $this->inbox->upload(InboxFixture::pdf('sw'), 'sw.pdf');
        AttachmentService::unlinkRef($this->store, 'inbox', $e['id']);   // Verweis verloren (etwa Rechnung gelöscht)
        self::assertSame(0, AttachmentService::sweep($this->store, time() + 2 * 86400));
        self::assertNotNull($this->att->get($e['attachment_id']));
    }
}
