<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Evidence;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (treffsicher) — Marken nur, wo wirklich Zweifel ist, und nie still an
 * den falschen Vertrag: leere Felder je Vorschlag, „voraussichtlich“ als
 * Hinweis, Preise aus der Rechnung gegen den Vertrag, gestützte
 * Auswahlfelder, Vorbelegung nur beim selben Versorger, Vertrag aus dem
 * Dokument. Alle Auslesungen sind erfunden (InboxFixture).
 */
#[CoversClass(Evidence::class)]
#[CoversClass(InboxSchema::class)]
#[CoversClass(Proposals::class)]
#[CoversClass(InboxService::class)]
final class InboxMarksTest extends ServiceTestCase
{
    private AttachmentService $att;
    private BillService $bills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function inbox(string $type, array $answer, string $needle): InboxService
    {
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $p = InboxFixture::provider('local', [$needle => $type], [$type => $answer]);
        $s = new InboxService($this->store, $this->i18n, $this->att, new ProviderRegistry($this->settings, $p),
            new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings),
            new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $s->attachActivity(new ActivityService($this->store, $this->settings, $this->i18n));
        return $s;
    }

    /** @return array{0: InboxService, 1: array} Dienst und Vorschau */
    private function read(string $type, array $pages, array $answer, string $needle, string $tag): array
    {
        $s = $this->inbox($type, $answer, $needle);
        $e = $s->upload(InboxFixture::pdf($tag), "$tag.pdf");
        $s->extract($e['id'], $pages);
        return [$s, $s->preview($e['id'])];
    }

    private static function byKind(array $proposals, string $kind): array
    {
        return array_values(array_filter($proposals, fn($p) => $p['kind'] === $kind));
    }

    private function gasMeter(): void
    {
        $all = $this->store->read('gas/meters.json', []);
        $all[0]['devices'][0]['serial'] = InboxFixture::METER;
        $all[0]['devices'][0]['installed_on'] = '2020-01-01';
        $this->store->write('gas/meters.json', $all);
    }

    private function gasContract(string $provider, array $extra = []): array
    {
        return $this->contracts->create('gas', $extra + ['meter_id' => 'm_gas_default', 'provider' => $provider, 'tariff_name' => 'Gas Basis',
            'start' => '2024-01-01', 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.0]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]], 'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 140.0]]]);
    }

    /** Rechnung ohne Vertrags- und Kundennummer (Zuordnung nur über Zähler und Versorger). */
    private static function invoiceWithoutNumbers(): array
    {
        $a = InboxFixture::invoiceAnswer();
        unset($a['contract_number'], $a['customer_number']);
        return $a;
    }

    // ── 1. Leere Felder je Vorschlag ─────────────────────────────────────

    public function testAnEmptyFieldMarksOnlyWhenAProposalNeedsIt(): void
    {
        $pages = InboxFixture::invoicePages();
        $raw = InboxFixture::invoiceAnswer();
        unset($raw['advance_new'], $raw['advance_new_from']);   // Schlussrechnung: kein neuer Abschlag
        unset($raw['reading_new_date']);                        // Stand ohne Datum
        $f = Evidence::check('invoice', $raw, $pages);
        self::assertSame(['empty', ['missing']], [$f['advance_new']['status'], $f['advance_new']['reasons']], 'fehlt im Dokument, keine Marke');
        self::assertSame(['check', ['empty']], [$f['reading_new_date']['status'], $f['reading_new_date']['reasons']], 'die Ablesung braucht ihr Datum');
        self::assertSame(1, Evidence::flagCount($f) - 2, 'außer den beiden Preisen nur das Datum');
        unset($raw['kind']);
        self::assertSame('empty', Evidence::check('invoice', $raw, $pages)['kind']['status'], 'ohne Art: Jahresabrechnung, keine Marke');
        // Verbrauch oder Betrag: eines genügt, ohne beide hat die Rechnung nichts zu vergleichen
        $one = $raw; unset($one['consumption']);
        self::assertSame('empty', Evidence::check('invoice', $one, $pages)['consumption']['status']);
        $none = $one; unset($none['total_amount']);
        $h = Evidence::check('invoice', $none, $pages);
        self::assertSame(['check', 'check'], [$h['consumption']['status'], $h['total_amount']['status']]);
        // Ergebnis ohne Art: aus Betrag und Abschlägen ableitbar, also nicht nötig
        $r = InboxFixture::invoiceAnswer(); unset($r['result_kind']);
        self::assertSame('empty', Evidence::check('invoice', $r, $pages)['result_kind']['status']);
        $r2 = $r; unset($r2['advances_total'], $r2['advances_paid']);
        self::assertSame('check', Evidence::check('invoice', $r2, $pages)['result_kind']['status'], 'ohne Abschläge nicht ableitbar');
    }

    public function testAContractIsCreatedWithoutAdvanceAndTheAdvanceStaysOpen(): void
    {
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['devices'][0]['serial'] = 'TEST-STROM-9';
        $this->store->write('strom/meters.json', $all);
        $a = InboxFixture::confirmationAnswer();
        unset($a['advance']);
        [$s, $p] = $this->read('contract_confirmation', InboxFixture::confirmationPages(), $a, 'Vertragsbestätigung', 'ca');
        $c = self::byKind($p['proposals'], 'contract_create')[0];
        self::assertFalse($c['check'], 'ohne Abschlag kein Zweifel am Vertrag');
        self::assertTrue($c['checked']);
        self::assertSame([['field' => 'advance', 'reason' => 'missing']], $c['open']);
        self::assertSame([], $c['data']['advance_payments'] ?? []);
        self::assertSame(0, $p['entry']['extraction']['flags']);

        // ein Abschlag mit erfundenem Zitat: nicht übernommen, offener Punkt, der Vertrag bleibt angehakt
        $b = InboxFixture::confirmationAnswer();
        $b['advance'] = ['value' => '92,00', 'quote' => 'Ihr monatlicher Abschlag: 92,00 €', 'page' => 1];
        [$s2, $q] = $this->read('contract_confirmation', InboxFixture::confirmationPages(), $b, 'Vertragsbestätigung', 'cb');
        $c = self::byKind($q['proposals'], 'contract_create')[0];
        self::assertSame('check', $q['entry']['extraction']['fields']['advance']['status'], 'das Feld bleibt markiert');
        self::assertFalse($c['check']);
        self::assertTrue($c['checked']);
        self::assertSame([['field' => 'advance', 'reason' => 'unsure']], $c['open']);
        self::assertSame([], $c['data']['advance_payments'] ?? [], 'ein unsicherer Wert geht nicht still in den Vertrag');
        // bestätigt der Nutzer den Wert, gehört er dazu
        $s2->update($q['entry']['id'], ['edits' => ['advance' => '82,00']]);
        $c = self::byKind($s2->preview($q['entry']['id'])['proposals'], 'contract_create')[0];
        self::assertSame(82.0, (float)$c['data']['advance_payments'][0]['amount_eur']);
        self::assertSame([], $c['open']);
    }

    public function testAnExpectedDateIsTakenAsAHintNotABlock(): void
    {
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['devices'][0]['serial'] = 'TEST-STROM-9';
        $this->store->write('strom/meters.json', $all);
        $pages = [str_replace('Ihr Lieferbeginn ist der 01.03.2026.', 'Ihr Lieferbeginn ist voraussichtlich der 01.03.2026.', InboxFixture::confirmationPages()[0])];
        $a = InboxFixture::confirmationAnswer();
        $a['supply_start'] = ['value' => '01.03.2026', 'quote' => 'Ihr Lieferbeginn ist voraussichtlich der 01.03.2026.', 'page' => 1];
        [, $p] = $this->read('contract_confirmation', $pages, $a, 'Vertragsbestätigung', 'vo');
        $f = $p['entry']['extraction']['fields']['supply_start'];
        self::assertSame(['ok', ['estimated']], [$f['status'], $f['reasons']]);
        $c = self::byKind($p['proposals'], 'contract_create')[0];
        self::assertFalse($c['check']);
        self::assertTrue($c['checked'], 'angehakt');
        self::assertSame([['field' => 'supply_start', 'reason' => 'estimated']], $c['hints'], 'mit Hinweis');
        self::assertSame(0, $p['entry']['extraction']['flags']);
    }

    // ── 2. Preise aus der Rechnung gegen den Vertrag ─────────────────────

    public function testAnInvoicePriceThatMatchesTheContractIsPresentWithoutMark(): void
    {
        $this->gasMeter();
        $c = $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.9]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.5]]]);
        [, $p] = $this->read('invoice', InboxFixture::invoicePages(), InboxFixture::invoiceAnswer(), 'Jahresabrechnung', 'pm');
        self::assertSame($c['id'], $p['assignment']['contract_id']);
        $wp = self::byKind($p['proposals'], 'contract_price')[0];
        self::assertSame(['exists', false], [$wp['status'], $wp['check']], 'Preiszeile am 01.01.2025 = 11,90 ct (gilt seit 2024)');
        $bp = self::byKind($p['proposals'], 'contract_base_price')[0];
        self::assertSame(['exists', false], [$bp['status'], $bp['check']]);
        $f = $p['entry']['extraction']['fields'];
        self::assertSame(['ok', ['price_from_invoice', 'price_matches']], [$f['working_prices']['status'], $f['working_prices']['reasons']]);
        self::assertSame('ok', $f['base_price']['status']);
        self::assertSame(0, $p['entry']['extraction']['flags']);

        // weicht der Vertrag ab (oder fehlt der Preis), bleibt die Marke
        $this->contracts->update('gas', $c['id'], ['working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.0]]]);
        [$s, $q] = $this->read('invoice', InboxFixture::invoicePages(), InboxFixture::invoiceAnswer(), 'Jahresabrechnung', 'pd');
        $wp = self::byKind($q['proposals'], 'contract_price')[0];
        self::assertSame(['new', true, false], [$wp['status'], $wp['check'], $wp['checked']]);
        self::assertSame('check', $q['entry']['extraction']['fields']['working_prices']['status']);
        self::assertSame(1, $q['entry']['extraction']['flags'], 'nur der Arbeitspreis, der Grundpreis passt');
    }

    public function testAYearlyBasePriceIsComparedPerYear(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.9]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.5]]]);
        $pages = InboxFixture::invoicePages();
        $pages[1] = str_replace('Grundpreis: 12,50 € pro Monat', 'Grundpreis: 150,00 € pro Jahr', $pages[1]);
        $a = InboxFixture::invoiceAnswer();
        $a['base_price'] = ['value' => '150,00', 'quote' => 'Grundpreis: 150,00 € pro Jahr', 'page' => 2];
        $a['base_price_period'] = ['value' => 'year', 'quote' => 'Grundpreis: 150,00 € pro Jahr', 'page' => 2];
        [, $p] = $this->read('invoice', $pages, $a, 'Jahresabrechnung', 'py');
        self::assertSame('exists', self::byKind($p['proposals'], 'contract_base_price')[0]['status']);
    }

    // ── 3. Auswahlfelder ─────────────────────────────────────────────────

    public function testAChoiceWithoutQuoteIsNotMarkedWhenOtherFieldsSupportIt(): void
    {
        $pages = InboxFixture::confirmationPages();
        $a = InboxFixture::confirmationAnswer();
        $a['utility'] = ['value' => 'strom', 'quote' => 'Bestätigung Ihres Stromliefervertrags', 'page' => 1];   // steht so nicht im Dokument
        $f = Evidence::check('contract_confirmation', $a, $pages)['utility'];
        self::assertSame(['ok', ['quote_not_found', 'supported']], [$f['status'], $f['reasons']], 'Tarif „Ökostrom …“ und kWh zeigen auf Strom');
        // ohne Stütze (kein Stichwort, keine Einheit): wie bisher markiert
        $only = ['utility' => $a['utility'], 'supplier' => $a['supplier'], 'supply_start' => $a['supply_start'],
                 'tariff_name' => ['value' => 'Fix 24', 'quote' => 'Fix 24', 'page' => 1]];
        self::assertSame('check', Evidence::check('contract_confirmation', $only, $pages)['utility']['status']);
        // die Wahl zeigt auf etwas anderes: keine Stütze
        $gas = $a;
        $gas['utility'] = ['value' => 'gas', 'quote' => 'Bestätigung Ihres Gasliefervertrags', 'page' => 1];
        self::assertSame('check', Evidence::check('contract_confirmation', $gas, $pages)['utility']['status']);
        // Art der Rechnung: der neue Abschlag stützt „Jahresabrechnung“
        $inv = InboxFixture::invoiceAnswer();
        $inv['kind'] = ['value' => 'annual', 'quote' => 'Abrechnung 2025', 'page' => 1];
        $k = Evidence::check('invoice', $inv, InboxFixture::invoicePages())['kind'];
        self::assertSame(['ok', ['quote_not_found', 'value_not_in_quote', 'supported']], [$k['status'], $k['reasons']]);
        $inv['kind']['value'] = 'final';
        self::assertSame('check', Evidence::check('invoice', $inv, InboxFixture::invoicePages())['kind']['status']);
    }

    public function testAMeterNumberAtAKnownMeterSupportsTheUtility(): void
    {
        $this->gasMeter();
        $pages = InboxFixture::invoicePages();
        $full = InboxFixture::invoiceAnswer();
        $full['utility'] = ['value' => 'gas', 'quote' => 'Ihre Energieabrechnung', 'page' => 1];
        self::assertSame('ok', Evidence::check('invoice', $full, $pages)['utility']['status'], 'm³ und kWh in den Zitaten: Gas');
        // ohne Einheiten und Stichwörter stützt nur der Bestand: die Zählernummer am bekannten Gaszähler
        $a = array_intersect_key($full, array_flip(['utility', 'supplier', 'period_from', 'period_to', 'meter_number', 'total_amount']));
        self::assertSame('check', Evidence::check('invoice', $a, $pages)['utility']['status'], 'ohne Bestand keine Stütze');
        [, $p] = $this->read('invoice', $pages, $a, 'Jahresabrechnung', 'mn');
        $u = $p['entry']['extraction']['fields']['utility'];
        self::assertSame(['ok', ['quote_not_found', 'value_not_in_quote', 'supported']], [$u['status'], $u['reasons']]);
        self::assertContains('meter_number', $p['suggested']['by']);
    }

    public function testWithoutResultKindTheSignFollowsAmountAndAdvancesNeverASilentDue(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899']);
        $pages = InboxFixture::invoicePages();
        $pages[0] = str_replace('1.722,70', '1.637,30', $pages[0]);
        $pages[1] = str_replace('Nachzahlung: 42,70 €', 'Guthaben: 42,70 €', $pages[1]);
        $a = InboxFixture::invoiceAnswer();
        unset($a['result_kind']);
        $a['total_amount'] = ['value' => '1.637,30', 'quote' => 'Rechnungsbetrag brutto: 1.637,30 €', 'page' => 1];
        $a['result_amount'] = ['value' => '42,70', 'quote' => 'Guthaben: 42,70 €', 'page' => 2];
        [, $p] = $this->read('invoice', $pages, $a, 'Jahresabrechnung', 'rs');
        self::assertSame('empty', $p['entry']['extraction']['fields']['result_kind']['status'], 'aus Betrag und Abschlägen ableitbar');
        $bill = self::byKind($p['proposals'], 'bill')[0];
        self::assertEquals(-42.7, $bill['data']['invoice']['result_eur'], '1.637,30 € < 1.680,00 € Abschläge: Guthaben, bis dahin still „Nachzahlung“');
    }

    // ── 4. Vorbelegung nur beim selben Versorger ─────────────────────────

    public function testTheContractIsPrefilledByDateOnlyForTheSameSupplier(): void
    {
        $this->gasMeter();
        $other = $this->gasContract('Anderer Versorger AG');
        [, $p] = $this->read('invoice', InboxFixture::invoicePages(), self::invoiceWithoutNumbers(), 'Jahresabrechnung', 'fo');
        self::assertNull($p['assignment']['contract_id'], 'nie der Vertrag eines anderen Lieferanten');
        self::assertNotContains('date', $p['suggested']['by']);
        self::assertSame([], self::byKind($p['proposals'], 'contract_price'), 'nichts am fremden Vertrag');
        self::assertSame([], self::byKind($p['proposals'], 'contract_advance'));
        $offer = self::byKind($p['proposals'], 'contract_create')[0];
        self::assertSame('inbox.proposal.contractFromInvoice', $offer['label_key'], 'stattdessen: Vertrag aus diesem Dokument anlegen');
        self::assertFalse($offer['checked'], 'nur als Angebot');
        self::assertSame(['Stadtwerke Musterstadt GmbH', '2025-01-01'], [$offer['data']['provider'], $offer['data']['start']]);
        self::assertSame('overlap', $offer['status'], 'der andere Vertrag läuft im Zeitraum');

        $own = $this->gasContract('Stadtwerke Musterstadt', ['start' => '2024-06-01']);
        [, $q] = $this->read('invoice', InboxFixture::invoicePages(), self::invoiceWithoutNumbers(), 'Jahresabrechnung', 'fs');
        self::assertSame($own['id'], $q['assignment']['contract_id'], 'derselbe Versorger, Rechtsform egal');
        self::assertContains('date', $q['suggested']['by']);
        self::assertSame([], self::byKind($q['proposals'], 'contract_create'));
        unset($other);
    }

    public function testSupplierNamesAreNormalised(): void
    {
        self::assertTrue(Proposals::supplierMatch('Stadtwerke Musterstadt GmbH', 'stadtwerke musterstadt'));
        self::assertTrue(Proposals::supplierMatch('E.ON Energie Deutschland GmbH', 'E.ON'));
        self::assertTrue(Proposals::supplierMatch('Musterenergie Nord AG', 'Musterenergie'));
        self::assertTrue(Proposals::supplierMatch('Muster-Energie Nord', 'Musterenergie Nord GmbH & Co. KG'));
        self::assertTrue(Proposals::supplierMatch('Nordwind Energie GmbH', 'Nordwindenergie'));
        self::assertFalse(Proposals::supplierMatch('Stadtwerke Musterstadt', 'Stadtwerke Beispielstadt'));
        self::assertFalse(Proposals::supplierMatch('Stadtwerke', 'Stadtwerke Musterstadt'), 'nur ein allgemeines Wort');
        self::assertFalse(Proposals::supplierMatch('', 'Stadtwerke Musterstadt'));
        self::assertFalse(Proposals::supplierMatch('Anderer Versorger AG', 'Stadtwerke Musterstadt GmbH'));
    }

    public function testTheNewAdvanceGoesToAFollowUpContractOnlyOfTheSameSupplier(): void
    {
        $this->gasMeter();
        $c = $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899', 'end' => '2025-12-31', 'auto_renews' => false]);
        $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Anderer Versorger AG', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 10.0]]]);
        [, $p] = $this->read('invoice', InboxFixture::invoicePages(), InboxFixture::invoiceAnswer(), 'Jahresabrechnung', 'fa');
        self::assertSame($c['id'], $p['assignment']['contract_id']);
        self::assertSame([], self::byKind($p['proposals'], 'contract_advance'), 'nicht an den Vertrag des neuen Lieferanten');
    }

    // ── 5. Vertrag aus dem Dokument ──────────────────────────────────────

    public function testASupplyStartLetterWithoutContractProposesOne(): void
    {
        $this->gasMeter();
        $pages = ['Stadtwerke Musterstadt GmbH: Wir bestätigen den Lieferbeginn Erdgas zum 01.04.2026. Zählernummer: TEST-4711-01. Vertragskonto: VK-1234'];
        $answer = [
            'utility' => ['value' => 'gas', 'quote' => 'Lieferbeginn Erdgas', 'page' => 1],
            'supplier' => ['value' => 'Stadtwerke Musterstadt GmbH', 'quote' => 'Stadtwerke Musterstadt GmbH', 'page' => 1],
            'supply_start' => ['value' => '01.04.2026', 'quote' => 'Lieferbeginn Erdgas zum 01.04.2026', 'page' => 1],
            'meter_number' => ['value' => InboxFixture::METER, 'quote' => 'Zählernummer: TEST-4711-01', 'page' => 1],
            'contract_number' => ['value' => 'VK-1234', 'quote' => 'Vertragskonto: VK-1234', 'page' => 1],
        ];
        [$s, $p] = $this->read('supply_start', $pages, $answer, 'Lieferbeginn', 'ls');
        $c = self::byKind($p['proposals'], 'contract_create')[0];
        self::assertSame('inbox.proposal.contractFromSupplyStart', $c['label_key']);
        self::assertFalse($c['check']);
        self::assertFalse($c['checked'], 'Angebot, nicht vorbelegt');
        self::assertSame(['working_price', 'base_price'], array_column($c['open'], 'field'), 'Preise fehlen noch');
        $res = $s->apply($p['entry']['id'], [$c['id']]);
        self::assertSame([], $res['failed']);
        $new = $this->contracts->list('gas')[0];
        self::assertSame(['Stadtwerke Musterstadt GmbH', '2026-04-01', 'VK-1234'], [$new['provider'], $new['start'], $new['contract_number']]);
        self::assertSame([$p['entry']['attachment_id']], $new['attachment_ids']);
        self::assertSame('exists', self::byKind($s->preview($p['entry']['id'])['proposals'], 'contract_create')[0]['status'] ?? 'exists',
            'danach nichts Neues mehr (der Vertrag ist zugeordnet)');
    }

    public function testAContractFromTheInvoiceTakesTheBookingInsteadOfAForeignContract(): void
    {
        $this->gasMeter();
        $foreign = $this->gasContract('Anderer Versorger AG');
        [$s, $p] = $this->read('invoice', InboxFixture::invoicePages(), self::invoiceWithoutNumbers(), 'Jahresabrechnung', 'cb');
        $offer = self::byKind($p['proposals'], 'contract_create')[0];
        $bill = self::byKind($p['proposals'], 'bill')[0];
        $book = self::byKind($p['proposals'], 'bill_book')[0];
        self::assertNull($bill['data']['contract_id']);
        $res = $s->apply($p['entry']['id'], [$offer['id'], $bill['id'], $book['id']]);
        self::assertSame([], $res['failed']);
        $created = array_values(array_filter($this->contracts->list('gas'), fn($c) => $c['id'] !== $foreign['id']))[0];
        $b = $this->bills->list('gas')[0];
        self::assertSame($created['id'], $b['contract_id'], 'gebucht am Vertrag des Versorgers dieser Rechnung');
        self::assertSame([], $this->contracts->get('gas', $foreign['id'])['special_payments'] ?? [], 'der fremde Vertrag bleibt unberührt');
        self::assertSame([['from' => '2025-01-01', 'to' => '2025-12-31', 'zustandszahl' => 0.9512, 'brennwert' => 11.25]], $b['factors'],
            'Zustandszahl und Brennwert laut Rechnung an der Rechnung');
    }

    public function testWithoutAContractOfTheSupplierTheBookingIsRefusedNotMovedToAForeignOne(): void
    {
        $this->gasMeter();
        $foreign = $this->gasContract('Anderer Versorger AG');
        [$s, $p] = $this->read('invoice', InboxFixture::invoicePages(), self::invoiceWithoutNumbers(), 'Jahresabrechnung', 'nb');
        $bill = self::byKind($p['proposals'], 'bill')[0];
        $book = self::byKind($p['proposals'], 'bill_book')[0];
        self::assertTrue($bill['checked']);
        self::assertFalse($book['checked'], 'ohne Vertrag dieses Versorgers nur als Angebot');
        self::assertSame([['field' => 'contract', 'reason' => 'missing']], $book['open']);
        $res = $s->apply($p['entry']['id'], [$bill['id'], $book['id']]);
        self::assertSame(['bill'], array_column($res['applied'], 'kind'));
        self::assertSame('errors.bill.noContract', $res['failed'][0]['code'] ?? null, 'abgelehnt mit Zeitraum');
        self::assertSame([], $this->contracts->get('gas', $foreign['id'])['special_payments'] ?? [], 'nichts am fremden Vertrag');
        self::assertNull($this->bills->list('gas')[0]['special_payment_id'] ?? null);
    }

    // ── 6. Dubletten nach Inhalt ─────────────────────────────────────────

    public function testTheSameContentInAnotherFileIsADuplicateAndAddsNothing(): void
    {
        $this->gasMeter();
        $c = $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899']);
        $s = $this->inbox('price_change', InboxFixture::priceChangeAnswer(), 'Preisanpassung');
        $a = $s->upload(InboxFixture::pdf('dup-a'), 'Preisanpassung.pdf');
        $b = $s->upload(InboxFixture::pdf('dup-b'), 'Scan Preisanpassung.pdf');   // andere Datei, andere Prüfsumme
        $s->extract($a['id'], InboxFixture::priceChangePages());
        $s->extract($b['id'], InboxFixture::priceChangePages());
        $pb = $s->preview($b['id']);
        self::assertSame([$a['id']], array_column($pb['duplicates'], 'id'), 'wohl dasselbe Dokument');
        $list = array_column($s->list(), 'duplicate_of', 'id');
        self::assertSame([$a['id'] => null, $b['id'] => $a['id']], [$a['id'] => $list[$a['id']], $b['id'] => $list[$b['id']]], 'das jüngere zeigt auf das ältere');
        $pa = $s->preview($a['id']);
        $s->apply($a['id'], array_column(array_filter($pa['proposals'], fn($p) => $p['checked']), 'id'));
        $after = $s->preview($b['id']);
        foreach ($after['proposals'] as $p) self::assertSame('exists', $p['status'], $p['kind'] . ': das zweite bringt nichts Neues');
        self::assertCount(1, array_filter((new ReminderService($this->store, $this->settings, $this->i18n))->list(), fn($r) => $r['next_due'] === '2026-03-31'),
            'der Termin nicht doppelt');
        self::assertNotNull($s->get($b['id']), 'nichts gelöscht');
        self::assertCount(2, $this->contracts->get('gas', $c['id'])['working_prices']);
        // ein anderer Stichtag ist ein anderes Dokument
        $other = InboxFixture::priceChangeAnswer();
        $other['valid_from'] = ['value' => '01.04.2027', 'quote' => 'Preisanpassung Erdgas zum 01.04.2026', 'page' => 1];
        self::assertFalse(InboxService::sameContent($s->get($a['id']), ['type' => 'price_change', 'edits' => [],
            'extraction' => ['fields' => Evidence::check('price_change', $other, InboxFixture::priceChangePages())]]));
    }

    // ── Zusammenführung mit „betrieb“: Kernaussage und Akte kennen die neuen Zustände ──

    public function testTheGistKnowsNoPricesOpenPointsAndDuplicates(): void
    {
        $this->gasMeter();
        // Vertrag ohne Arbeitspreis: Die automatisch geprüfte Rechnung hat keine Preise
        $c = $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Gas Basis',
            'start' => '2024-01-01', 'contract_number' => 'VK-778899', 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]]]);
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-12-31', 'counter' => 12345]);
        $this->settings->set(['gas_conversion_factors' => [['zustandszahl' => 0.9512, 'brennwert' => 11.25]]]);   // wie die Rechnung: Menge passt
        $a = InboxFixture::invoiceAnswer();
        unset($a['working_prices']);
        $s = $this->inbox('invoice', $a, 'Jahresabrechnung');
        $s->attachGist(new \Energietracker\Services\Inbox\Gist($this->meters, $this->contracts, $this->consumption, $this->bills, $this->readings));
        $e = $s->upload(InboxFixture::pdf('g1'), 'Rechnung.pdf');
        $s->extract($e['id'], InboxFixture::invoicePages());
        $pv = $s->preview($e['id']);
        $ids = array_column(array_filter($pv['proposals'], fn($p) => $p['kind'] === 'bill' || $p['kind'] === 'reading'), 'id');
        $s->update($e['id'], ['edits' => ['consumption' => $pv['entry']['extraction']['fields']['consumption']['value']]]);
        $pv = $s->preview($e['id']);
        $ids = array_column(array_filter($pv['proposals'], fn($p) => in_array($p['kind'], ['bill', 'reading'], true) && $p['status'] === 'new'), 'id');
        $res = $s->apply($e['id'], $ids);
        $g = $res['gist'];
        self::assertSame('no_prices', $g['check']['verdict'] ?? null, 'die automatisch geprüfte Rechnung');
        self::assertSame('inbox.gist.invoice.noPrices', $g['key']);
        self::assertSame('inbox.gist.action.addPrices', $g['action']['key'] ?? null);
        self::assertSame('#/utility/gas/contracts', $g['action']['href'] ?? null);
        self::assertContains('billCheck.noPrices.at', array_column($g['details'], 'key'), 'die Abschnitte ohne Preis');
        unset($c);

        // offene Punkte: Vertragsbestätigung ohne Abschlag
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['devices'][0]['serial'] = 'TEST-STROM-9';
        $this->store->write('strom/meters.json', $all);
        $b = InboxFixture::confirmationAnswer();
        unset($b['advance']);
        $s2 = $this->inbox('contract_confirmation', $b, 'Vertragsbestätigung');
        $s2->attachGist(new \Energietracker\Services\Inbox\Gist($this->meters, $this->contracts, $this->consumption, $this->bills, $this->readings));
        $x = $s2->upload(InboxFixture::pdf('g2'), 'Bestaetigung.pdf');
        $s2->extract($x['id'], InboxFixture::confirmationPages());
        $open = array_values(array_filter($s2->preview($x['id'])['gist']['details'], fn($d) => $d['key'] === 'inbox.note.missing'));
        self::assertSame(['advance'], $open[0]['params']['fields'] ?? null, 'die Kernaussage nennt den offenen Abschlag');

        // Doppel: das zweite inhaltsgleiche Dokument — Kernaussage mit duplicate_of, Knopf „ablegen“, Akte kenntlich
        $y = $s2->upload(InboxFixture::pdf('g3'), 'Scan Bestaetigung.pdf');
        $s2->extract($y['id'], InboxFixture::confirmationPages());
        $first = $s2->preview($x['id']);
        $s2->apply($x['id'], array_column(array_filter($first['proposals'], fn($p) => $p['checked']), 'id'));
        $gy = $s2->preview($y['id'])['gist'];
        self::assertSame($x['id'], $gy['duplicate_of']);
        self::assertSame('file', $gy['action']['kind'] ?? null, 'das andere hat schon alles übernommen: ablegen');
        $s2->update($y['id'], ['status' => 'filed', 'assignment' => ['utility' => 'strom', 'meter_id' => 'm_strom_default', 'contract_id' => null]]);
        $dossier = new \Energietracker\Services\DossierService($s2, $this->att, $this->bills, $this->contracts, $this->meters, $this->readings, $this->i18n);
        $items = [];
        foreach ($dossier->forMeter('strom', 'm_strom_default') as $i) if ($i['kind'] === 'inbox') $items[$i['inbox_id']] = $i['duplicate_of'] ?? null;
        self::assertTrue(array_key_exists($x['id'], $items) && array_key_exists($y['id'], $items), 'beide in der Akte');
        self::assertSame([$x['id'] => null, $y['id'] => $x['id']], [$x['id'] => $items[$x['id']], $y['id'] => $items[$y['id']]], 'das jüngere als Doppel kenntlich');
    }

    // ── Nachbesserung nach dem Nutzentest ────────────────────────────────

    private function stromMeter(): void
    {
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['devices'][0]['serial'] = 'TEST-STROM-9';
        $all[0]['devices'][0]['installed_on'] = '2020-01-01';
        $this->store->write('strom/meters.json', $all);
    }

    private function stromContract(string $provider, array $extra = []): array
    {
        return $this->contracts->create('strom', $extra + ['meter_id' => 'm_strom_default', 'provider' => $provider, 'tariff_name' => 'Strom Basis',
            'start' => '2024-01-01', 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 35.0]]]);
    }

    /** @return list<array<string,mixed>> Vorschläge „Vorgänger endet“ einer Vertragsbestätigung (Beginn 01.03.2026) */
    private function predecessorProposals(string $tag): array
    {
        [, $p] = $this->read('contract_confirmation', InboxFixture::confirmationPages(), InboxFixture::confirmationAnswer(), 'Vertragsbestätigung', $tag);
        return array_values(array_filter($p['proposals'], fn($x) => $x['label_key'] === 'inbox.proposal.predecessorEnd'));
    }

    public function testThePredecessorEndIsPreselectedOnASwitchOrTariffChange(): void
    {
        $this->stromMeter();
        $old = $this->stromContract('Altversorger');
        $end = $this->predecessorProposals('w1');
        self::assertCount(1, $end);
        self::assertSame(['new', true, '2026-02-28'], [$end[0]['status'], $end[0]['checked'], $end[0]['data']['end']], 'Wechsel: vorbelegt am Vortag');

        // derselbe Versorger, anderer Tarif: Tarifwechsel, vorbelegt
        $this->contracts->update('strom', $old['id'], ['provider' => 'Musterenergie Nord AG', 'tariff_name' => 'Ökostrom Flex']);
        self::assertTrue($this->predecessorProposals('w2')[0]['checked'], 'Tarifwechsel');
        // derselbe Versorger, anderer Vertrag (Nummer), gleicher Tarif: vorbelegt
        $this->contracts->update('strom', $old['id'], ['tariff_name' => 'Ökostrom Fix 24', 'contract_number' => 'ST-11-0001']);
        self::assertTrue($this->predecessorProposals('w3')[0]['checked'], 'andere Vertragsnummer');
        // derselbe Versorger, derselbe Tarif und dieselbe Nummer: wohl derselbe Vertrag — nur als Angebot
        $this->contracts->update('strom', $old['id'], ['contract_number' => 'ST-55-1234']);
        self::assertFalse($this->predecessorProposals('w4')[0]['checked'], 'offenbar derselbe Vertrag');
        // ein Schattentarif ist kein Vorgänger
        $this->stromContract('Vergleichsanbieter', ['is_shadow' => true, 'start' => '2025-06-01', 'working_prices' => [['from' => '2025-06-01', 'ct_per_kwh' => 29.0]]]);
        self::assertSame(['Musterenergie Nord AG'], array_column(array_column($this->predecessorProposals('w5'), 'params'), 'provider'));
    }

    public function testThePredecessorEndStaysAnOfferWhenAmbiguousOrBackedByADocument(): void
    {
        $this->stromMeter();
        // ein Ende, das eine Kündigung belegt (nach dem neuen Beginn): Widerspruch, nicht vorbelegt
        $c = $this->stromContract('Altversorger', ['end' => '2026-06-30', 'auto_renews' => false]);
        $end = $this->predecessorProposals('b1')[0];
        self::assertSame(['conflict', false], [$end['status'], $end['checked']]);
        self::assertSame(['end' => '2026-06-30'], $end['detail']);
        // ein Ende ohne Beleg (verlängert sich): vorbelegt
        $this->contracts->update('strom', $c['id'], ['auto_renews' => null]);
        self::assertTrue($this->predecessorProposals('b2')[0]['checked']);
        // zwei offene Vorgänger: nur der jüngste vorbelegt
        $this->contracts->update('strom', $c['id'], ['end' => null]);
        $this->stromContract('Zwischenversorger', ['start' => '2025-01-01', 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 33.0]]]);
        $two = $this->predecessorProposals('b3');
        self::assertSame(['Zwischenversorger' => true, 'Altversorger' => false], array_combine(array_column(array_column($two, 'params'), 'provider'), array_column($two, 'checked')));
        // Gruppenvertrag (uneindeutig): nur als Angebot
        $this->contracts->delete('strom', $c['id']);
        foreach ($this->contracts->list('strom') as $x) $this->contracts->delete('strom', (string)$x['id']);
        $second = $this->meters->create('strom', ['name' => 'Zweiter', 'icon' => '']);
        // Unterzähler (uneindeutig, welcher Vertrag endet): nur als Angebot
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['parent_meter_id'] = (string)$second['id'];
        $this->store->write('strom/meters.json', $all);
        $sub = $this->stromContract('Altversorger');
        $s = $this->predecessorProposals('b3s');
        self::assertCount(1, $s);
        self::assertFalse($s[0]['checked'], 'Unterzähler: nur Angebot');
        $this->contracts->delete('strom', (string)$sub['id']);
        $all[0]['parent_meter_id'] = null;
        $this->store->write('strom/meters.json', $all);
        $gid = $this->groupForStrom();
        $this->contracts->create('strom', ['meter_group_id' => $gid, 'provider' => 'Gruppenversorger', 'tariff_name' => 'HT/NT', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]]]);
        $g = $this->predecessorProposals('b4');
        self::assertCount(1, $g);
        self::assertFalse($g[0]['checked'], 'Gruppenvertrag: nur Angebot');
    }

    /** Beide Stromzähler in eine Gruppe (für den Gruppenvertrag). */
    private function groupForStrom(): string
    {
        $all = $this->store->read('strom/meters.json', []);
        $gid = 'g_strom_test';
        foreach ($all as $i => $_) $all[$i]['meter_group_id'] = $gid;
        $this->store->write('strom/meters.json', $all);
        $this->store->write('strom/meter_groups.json', [['id' => $gid, 'name' => 'HT/NT']]);
        return $gid;
    }

    public function testTheOldReadingOfAnInvoiceIsPreselectedWhenItFits(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899']);
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-06-30', 'counter' => 11800]);
        $old = fn(array $p) => array_values(array_filter($p['proposals'], fn($x) => $x['kind'] === 'reading' && $x['data']['date'] === '2024-12-31'))[0] ?? null;
        [, $p] = $this->read('invoice', InboxFixture::invoicePages(), InboxFixture::invoiceAnswer(), 'Jahresabrechnung', 'r1');
        self::assertSame(['new', true], [$old($p)['status'], $old($p)['checked']], 'zwischen 11.800 (30.06.2024) und dem Endstand 13.580: vorbelegt');

        // passt nicht zum vorigen Stand (kleiner): nur als Angebot
        $this->store->write('gas/readings.json', []);
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-06-30', 'counter' => 12500]);
        [, $q] = $this->read('invoice', InboxFixture::invoicePages(), InboxFixture::invoiceAnswer(), 'Jahresabrechnung', 'r2');
        self::assertFalse($old($q)['checked'], 'nicht monoton');
        // größer als der nächste bekannte Stand: nur als Angebot
        $this->store->write('gas/readings.json', []);
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2025-03-31', 'counter' => 12000]);
        [, $q] = $this->read('invoice', InboxFixture::invoicePages(), InboxFixture::invoiceAnswer(), 'Jahresabrechnung', 'r2b');
        self::assertFalse($old($q)['checked'], 'größer als der nächste Stand');
        // größer als der Endstand derselben Rechnung (Stand vertauscht): nur als Angebot
        $this->store->write('gas/readings.json', []);
        $a = InboxFixture::invoiceAnswer();
        $a['reading_new']['value'] = '12.000';
        [, $q] = $this->read('invoice', InboxFixture::invoicePages(), $a, 'Jahresabrechnung', 'r2c');
        self::assertFalse($old($q)['checked'], 'größer als der Endstand derselben Rechnung');

        // Zählertausch zwischen den Nachbarn: nicht zu entscheiden
        $this->store->write('gas/readings.json', []);
        $all = $this->store->read('gas/meters.json', []);
        $all[0]['devices'][0]['removed_on'] = '2024-09-01';
        $all[0]['devices'][0]['final_counter'] = 12000;
        $all[0]['devices'][] = ['id' => 'd_neu', 'serial' => 'TEST-NEU', 'installed_on' => '2024-09-01', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null];
        $this->store->write('gas/meters.json', $all);
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-06-30', 'counter' => 11800]);
        [, $s] = $this->read('invoice', InboxFixture::invoicePages(), InboxFixture::invoiceAnswer(), 'Jahresabrechnung', 'r3');
        self::assertFalse($old($s)['checked'], 'Zählertausch dazwischen');
    }

    public function testAMarkedOrExistingOldReadingIsNotPreselected(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899']);
        $a = InboxFixture::invoiceAnswer();
        $a['reading_old'] = ['value' => '12.345', 'quote' => 'Zählerstand alt: 12.345 m³', 'page' => 1];   // Zitat steht nicht im Dokument
        [, $p] = $this->read('invoice', InboxFixture::invoicePages(), $a, 'Jahresabrechnung', 'm1');
        $old = array_values(array_filter($p['proposals'], fn($x) => $x['kind'] === 'reading' && $x['data']['date'] === '2024-12-31'))[0];
        self::assertSame([true, false], [$old['check'], $old['checked']], 'markiert: nicht vorbelegt');
        // an dem Tag steht schon ein Stand: vorhanden bzw. Widerspruch wie bisher
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-12-31', 'counter' => 12340]);
        [, $q] = $this->read('invoice', InboxFixture::invoicePages(), InboxFixture::invoiceAnswer(), 'Jahresabrechnung', 'm2');
        $old = array_values(array_filter($q['proposals'], fn($x) => $x['kind'] === 'reading' && $x['data']['date'] === '2024-12-31'))[0];
        self::assertSame(['conflict', false], [$old['status'], $old['checked']]);
    }

    public function testMissingPricesShowEvenWhenTheQuantityDiffers(): void
    {
        $this->gasMeter();
        $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Gas Basis',
            'start' => '2024-01-01', 'contract_number' => 'VK-778899', 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]]]);
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-12-31', 'counter' => 12345]);
        // ohne passende Gas-Umrechnung weicht die Menge ab (Standardfaktor statt 0,9512 × 11,25)
        $a = InboxFixture::invoiceAnswer();
        unset($a['working_prices']);
        $s = $this->inbox('invoice', $a, 'Jahresabrechnung');
        $s->attachGist(new \Energietracker\Services\Inbox\Gist($this->meters, $this->contracts, $this->consumption, $this->bills, $this->readings));
        $e = $s->upload(InboxFixture::pdf('np'), 'Rechnung.pdf');
        $s->extract($e['id'], InboxFixture::invoicePages());
        $s->update($e['id'], ['edits' => ['consumption' => 13216]]);
        $pv = $s->preview($e['id']);
        $res = $s->apply($e['id'], array_column(array_filter($pv['proposals'], fn($p) => in_array($p['kind'], ['bill', 'reading'], true) && $p['status'] === 'new'), 'id'));
        $bill = $this->bills->list('gas')[0];
        $cmp = $this->bills->compare('gas', (string)$bill['id']);
        self::assertSame('check', $cmp['verdict'], 'die Menge weicht ab: das Urteil bleibt „prüfen“');
        self::assertContains('price_missing', $cmp['reasons']);
        self::assertNotSame([], $cmp['price_missing_at'], 'die Abschnitte ohne Preis stehen trotzdem da (Sprung zum Vertrag)');
        $g = $res['gist'];
        self::assertSame('inbox.gist.invoice.offQuantityNoPrices', $g['key'], 'die Kernaussage nennt beides');
        self::assertNotNull($g['params']['pct']);
        self::assertContains('billCheck.noPrices.at', array_column($g['details'], 'key'));
        self::assertSame('link', $g['action']['kind'] ?? null);
    }

    // ── Nachbesserung 2: Nettopreise aus Rechnungen ──────────────────────

    /** Erfundene Rechnung mit Nettopreisen: Arbeits- und Grundpreis netto, Steuersatz je Zeile. */
    private static function netPages(array $lines): array
    {
        $p = InboxFixture::invoicePages();
        $p[1] = str_replace("Arbeitspreis ab 01.01.2025: 11,90 ct/kWh\nGrundpreis: 12,50 € pro Monat", implode("\n", $lines), $p[1]);
        return $p;
    }

    private static function netAnswer(array $items, ?array $base = null): array
    {
        $a = InboxFixture::invoiceAnswer();
        $a['working_prices'] = ['value' => $items];
        if ($base !== null) {
            [$a['base_price'], $a['base_price_basis'], $a['base_price_vat_pct']] = $base;
            $a['base_price_period'] = ['value' => 'month', 'quote' => $base[0]['quote'], 'page' => 2];
        } else {
            unset($a['base_price'], $a['base_price_period']);
        }
        return $a;
    }

    /** @return array{0: array, 1: list<array>} Feld der Arbeitspreise und die Preisvorschläge */
    private function netRead(array $lines, array $items, ?array $base, string $tag): array
    {
        [, $p] = $this->read('invoice', self::netPages($lines), self::netAnswer($items, $base), 'Jahresabrechnung', $tag);
        return [$p['entry']['extraction']['fields'], array_values(array_filter($p['proposals'], fn($x) => in_array($x['kind'], ['contract_price', 'contract_base_price'], true)))];
    }

    public function testANetPriceWithItsRateIsComparedGross(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 11.9]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.5]]]);
        $wq = 'Arbeitspreis ab 01.01.2025: 10,00 ct/kWh netto zzgl. 19 % USt';
        $bq = 'Grundpreis: 10,50 € pro Monat netto zzgl. 19 % USt';
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 2];
        // neue Auslesung: das Modell nennt netto und den Satz
        [$fields, $props] = $this->netRead([$wq, $bq], [['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq, 'page' => 2]],
            [$f('10,50', $bq), $f('net', $bq), $f('19', $bq)], 'n1');
        $it = $fields['working_prices']['items'][0];
        self::assertSame('ok', $it['status'], '10,00 netto + 19 % = 11,90 brutto wie im Vertrag');
        self::assertContains('price_matches', $it['reasons']);
        self::assertContains('net_price', $it['reasons']);
        self::assertSame(['vat_pct' => 19.0, 'gross' => 11.9], $it['net']);
        self::assertSame('ok', $fields['base_price']['status'], '10,50 netto = 12,50 brutto (gerundet)');
        self::assertSame(['exists', 'exists'], array_column($props, 'status'));
        self::assertSame(['ok', 'ok'], [$fields['base_price_basis']['status'], $fields['base_price_vat_pct']['status']], 'Hilfsfelder markieren nie');

        // schon ausgelesen (ohne die neuen Felder): aus dem Zitat abgeleitet
        [$fields] = $this->netRead([$wq, $bq], [['from' => '01.01.2025', 'ct' => '10,00', 'quote' => $wq, 'page' => 2]], [$f('10,50', $bq), null, null], 'n2');
        self::assertSame(['ok', 'ok'], [$fields['working_prices']['items'][0]['status'], $fields['base_price']['status']], 'Zitat „netto zzgl. 19 % USt“ genügt');

        // nicht sicher erkannt (kein Satz im Zitat): die Marke bleibt wie bisher, der Preis wie gedruckt
        $q = 'Arbeitspreis ab 01.01.2025: 10,00 ct/kWh';
        [$fields, $props] = $this->netRead([$q], [['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $q, 'page' => 2]], null, 'n3');
        self::assertSame('check', $fields['working_prices']['items'][0]['status']);
        self::assertNotContains('net_price', $fields['working_prices']['items'][0]['reasons']);
        self::assertSame([10.0, true], [$props[0]['data']['value'], $props[0]['check']]);

        // ein Hilfsfeld, dessen Wert nicht im Zitat steht, markiert den Preis nicht (brutto wie im Vertrag)
        $q = 'Arbeitspreis ab 01.01.2025: 11,90 ct/kWh';
        [$fields] = $this->netRead([$q], [['from' => '01.01.2025', 'ct' => '11,90', 'basis' => 'gross', 'vat_pct' => '19', 'quote' => $q, 'page' => 2]], null, 'n4');
        self::assertSame('ok', $fields['working_prices']['items'][0]['status'], implode(',', $fields['working_prices']['items'][0]['reasons']));
        self::assertContains('price_matches', $fields['working_prices']['items'][0]['reasons']);
    }

    public function testANetPriceThatDiffersGoesGrossIntoTheContractAndStaysFlagged(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 12.5]]]);
        $wq = 'Arbeitspreis ab 01.01.2025: 10,00 ct/kWh netto zzgl. 19 % USt';
        [$fields, $props] = $this->netRead([$wq], [['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq, 'page' => 2]], null, 'd1');
        self::assertSame('check', $fields['working_prices']['items'][0]['status'], 'weicht ab: Marke wie bisher');
        self::assertSame([11.9, true, 'conflict'], [$props[0]['data']['value'], $props[0]['check'], $props[0]['status']], 'brutto vorgeschlagen');
        self::assertContains(['field' => 'working_prices', 'reason' => 'net_price'], $props[0]['hints']);
    }

    /**
     * Erfundene Gas-Vertragsbestätigung mit Preisen „brutto inkl. 7 % USt“ (ermäßigter Satz) —
     * je Preiszeile oder (`$footnote`) nur einmal unter den Preisen, dann trägt ihn allein das Feld `vat_pct`.
     */
    private function confirmedGasContractAt7(bool $footnote = false): array
    {
        $vat = $footnote ? '' : ' brutto inkl. 7 % USt';
        $pages = ["Stadtwerke Musterstadt GmbH\nVertragsbestätigung Erdgas\nIhr Lieferbeginn ist der 01.01.2024.\nTarif: Gas Basis\n"
            . "Arbeitspreis: 11,77 ct/kWh$vat\nGrundpreis: 12,84 € pro Monat$vat\n" . ($footnote ? "Alle Preise brutto inkl. 7 % USt\n" : '')
            . "Vertragsnummer: VK-778899\nZählernummer: TEST-4711-01"];
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        $a = ['utility' => $f('gas', 'Vertragsbestätigung Erdgas'), 'supplier' => $f('Stadtwerke Musterstadt GmbH', 'Stadtwerke Musterstadt GmbH'),
            'supply_start' => $f('01.01.2024', 'Ihr Lieferbeginn ist der 01.01.2024.'), 'tariff_name' => $f('Gas Basis', 'Tarif: Gas Basis'),
            'working_price' => $f('11,77', "Arbeitspreis: 11,77 ct/kWh$vat"),
            'base_price' => $f('12,84', "Grundpreis: 12,84 € pro Monat$vat"), 'base_price_period' => $f('month', 'Grundpreis: 12,84 € pro Monat'),
            'vat_pct' => $f('7', $footnote ? 'Alle Preise brutto inkl. 7 % USt' : 'inkl. 7 % USt'),
            'contract_number' => $f('VK-778899', 'Vertragsnummer: VK-778899'), 'meter_number' => $f(InboxFixture::METER, 'Zählernummer: TEST-4711-01')];
        [$s, $pv] = $this->read('contract_confirmation', $pages, $a, 'Vertragsbestätigung', $footnote ? 'c7f' : 'c7');
        $s->apply($pv['entry']['id'], array_column(array_filter($pv['proposals'], fn($p) => $p['kind'] === 'contract_create'), 'id'));
        $c = array_values(array_filter($this->contracts->list('gas'), fn($x) => ($x['contract_number'] ?? '') === 'VK-778899'))[0] ?? [];
        self::assertSame([11.77, 12.84], [$c['working_prices'][0]['ct_per_kwh'] ?? null, $c['base_prices'][0]['eur_per_month'] ?? null], json_encode(array_column($pv['proposals'], 'kind')));
        self::assertNotSame([], $c['attachment_ids'] ?? [], 'das Dokument hängt am Vertrag');
        return $c;
    }

    public function testSameNetPriceWithAnotherVatRateIsAHintNotAMark(): void
    {
        $this->gasMeter();
        $this->confirmedGasContractAt7();
        // die Rechnung weist netto mit 19 % aus: derselbe Nettopreis (11,00 ct, 12,00 €), nur ein anderer Satz
        $wq = 'Arbeitspreis ab 01.01.2025: 11,00 ct/kWh netto, USt 19 %';
        $bq = 'Grundpreis: 12,00 € pro Monat netto, USt 19 %';
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 2];
        [$fields, $props] = $this->netRead([$wq, $bq], [['from' => '01.01.2025', 'ct' => '11,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq, 'page' => 2]],
            [$f('12,00', $bq), $f('net', $bq), $f('19', $bq)], 'v1');
        $it = $fields['working_prices']['items'][0];
        self::assertSame('ok', $it['status'], 'gleicher Preis, anderer Steuersatz: keine Marke');
        self::assertContains('vat_changed', $it['reasons']);
        self::assertNotContains('price_matches', $it['reasons']);
        self::assertSame('ok', $fields['base_price']['status']);
        $wp = array_values(array_filter($props, fn($p) => $p['kind'] === 'contract_price'))[0];
        self::assertSame(['new', false, true, '2025-01-01', 13.09], [$wp['status'], $wp['check'], $wp['checked'], $wp['data']['from'], $wp['data']['value']],
            'neue Preiszeile ab dem Tag der Rechnung, brutto mit 19 %');
        self::assertContains(['field' => 'working_prices', 'reason' => 'vat_changed'], $wp['hints']);
        $bp = array_values(array_filter($props, fn($p) => $p['kind'] === 'contract_base_price'))[0];
        self::assertSame([true, 14.28], [$bp['checked'], $bp['data']['value']]);

        // ein anderer Nettopreis bei anderem Satz: Marke wie bisher
        $wq2 = 'Arbeitspreis ab 01.01.2025: 11,50 ct/kWh netto, USt 19 %';
        [$fields] = $this->netRead([$wq2], [['from' => '01.01.2025', 'ct' => '11,50', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq2, 'page' => 2]], null, 'v2');
        self::assertSame('check', $fields['working_prices']['items'][0]['status']);
    }

    public function testTheContractsRateMayStandOnceUnderThePricesOfTheConfirmation(): void
    {
        // „Alle Preise brutto inkl. 7 % USt“ unter den Preisen: der Satz steht nur im Feld `vat_pct` der Bestätigung
        $this->gasMeter();
        $this->confirmedGasContractAt7(true);
        $wq = 'Arbeitspreis ab 01.01.2025: 11,00 ct/kWh netto, USt 19 %';
        [$fields, $props] = $this->netRead([$wq], [['from' => '01.01.2025', 'ct' => '11,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq, 'page' => 2]], null, 'vf');
        self::assertSame('ok', $fields['working_prices']['items'][0]['status']);
        self::assertContains('vat_changed', $fields['working_prices']['items'][0]['reasons']);
        self::assertSame(['new', true, 13.09], [$props[0]['status'], $props[0]['checked'], $props[0]['data']['value']]);
    }

    public function testTheModelsNetBasisCountsWhenTheRateStandsInTheQuote(): void
    {
        // Tabelle mit Spalte „Netto“ und „USt 19 %“ je Zeile: das Zitat der Zeile trägt den Satz, „netto“ steht im Spaltenkopf
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 11.9]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.5]]]);
        $wq = 'Arbeitspreis ab 01.01.2025: 10,00 ct/kWh 19 %';
        $bq = 'Grundpreis: 10,50 € pro Monat 19 %';
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 2];
        $lines = ['Preise          Netto        USt', $wq, $bq];
        [$fields] = $this->netRead($lines, [['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq, 'page' => 2]],
            [$f('10,50', $bq), ['value' => 'net', 'quote' => null, 'page' => null], $f('19', $bq)], 'mb');
        self::assertSame(['ok', 'ok'], [$fields['working_prices']['items'][0]['status'], $fields['base_price']['status']], 'netto laut Modell, Satz im Zitat');
        self::assertSame(['ok', 'net'], [$fields['base_price_basis']['status'], $fields['base_price_basis']['value']], 'Hilfsfeld ohne Zitat: keine Marke');
        self::assertContains('net_price', $fields['working_prices']['items'][0]['reasons']);
        self::assertContains('net_price', $fields['base_price']['reasons']);
        // eine ältere Auslesung ohne die Hilfsfelder: im Zitat steht nicht, ob netto — die Marke bleibt
        [$fields] = $this->netRead($lines, [['from' => '01.01.2025', 'ct' => '10,00', 'quote' => $wq, 'page' => 2]], [$f('10,50', $bq), null, null], 'mo');
        self::assertSame(['check', 'check'], [$fields['working_prices']['items'][0]['status'], $fields['base_price']['status']], 'erst neu auslesen hilft');
    }

    public function testAContractFromTheInvoiceGetsTheGrossStandingCharge(): void
    {
        // kein Vertrag des Versorgers: Angebot „Vertrag aus diesem Dokument“; der bestätigte Grundpreis geht brutto hinein
        $this->gasMeter();
        $bq = 'Grundpreis: 10,50 € pro Monat netto zzgl. 19 % USt';
        $f = fn($v, string $q) => ['value' => $v, 'quote' => $q, 'page' => 2];
        [$s, $p] = $this->read('invoice', self::netPages([$bq]), self::netAnswer([], [$f('10,50', $bq), $f('net', $bq), $f('19', $bq)]), 'Jahresabrechnung', 'cf');
        $s->update($p['entry']['id'], ['edits' => ['base_price' => '10,50']]);
        $offer = self::byKind($s->preview($p['entry']['id'])['proposals'], 'contract_create')[0] ?? null;
        self::assertSame('inbox.proposal.contractFromInvoice', $offer['label_key'] ?? null);
        self::assertSame([['from' => '2025-01-01', 'eur_per_month' => 12.495]], $offer['data']['base_prices'], '10,50 netto + 19 % = 12,495 brutto');
    }

    // ── Nachbesserung 3: Satz aus der Steuerspalte oder der einen Umsatzsteuerzeile ──

    private const TAX_HEAD = 'Position        Menge        Preis netto        USt %        Betrag netto';
    private const TAX_WP = 'Arbeitspreis ab 01.01.2025        13.216 kWh        10,00 ct/kWh        19,00        1.321,60 €';
    private const TAX_BP = 'Grundpreis        12 Monate        10,50 €/Monat        19,00        126,00 €';

    /** Antwort des Modells: netto laut Modell, kein Satz im Zitat, keiner genannt. */
    private static function columnAnswer(string $wq, string $bq): array
    {
        $f = fn($v, ?string $q) => ['value' => $v, 'quote' => $q, 'page' => $q === null ? null : 2];
        return self::netAnswer([['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'quote' => $wq, 'page' => 2]], [$f('10,50', $bq), $f('net', null), $f(null, null)]);
    }

    private function grossContract(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 11.9]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.5]]]);
    }

    public function testARateFromTheTaxColumnConvertsTheNetPrice(): void
    {
        $this->grossContract();
        $wq = (string)preg_replace('/\s+/', ' ', self::TAX_WP);
        $bq = (string)preg_replace('/\s+/', ' ', self::TAX_BP);
        [, $p] = $this->read('invoice', self::netPages([self::TAX_HEAD, self::TAX_WP, self::TAX_BP]), self::columnAnswer($wq, $bq), 'Jahresabrechnung', 'tc');
        $fields = $p['entry']['extraction']['fields'];
        $it = $fields['working_prices']['items'][0];
        self::assertEquals(['vat_pct' => 19, 'from' => 'column'], $it['vat_read'] ?? null, 'gespeichert (JSON: 19)');
        self::assertSame(['ok', 'ok'], [$it['status'], $fields['base_price']['status']], '10,00 und 10,50 netto + 19 % aus der Spalte = wie im Vertrag');
        self::assertSame([['vat_pct' => 19.0, 'gross' => 11.9], true], [$it['net'], in_array('price_matches', $it['reasons'], true)]);
        // ohne Kopf der Steuerspalte: die Marke bleibt, der Preis wie gedruckt
        [, $p] = $this->read('invoice', self::netPages([str_replace('USt %', 'Steuer', self::TAX_HEAD), self::TAX_WP, self::TAX_BP]), self::columnAnswer($wq, $bq), 'Jahresabrechnung', 'tn');
        self::assertSame(['check', 'check'], [$p['entry']['extraction']['fields']['working_prices']['items'][0]['status'], $p['entry']['extraction']['fields']['base_price']['status']]);
    }

    public function testOneVatLineConvertsTwoRatesKeepTheMark(): void
    {
        $this->grossContract();
        $wq = 'Arbeitspreis ab 01.01.2025: 10,00 ct/kWh netto';
        $bq = 'Grundpreis: 10,50 € pro Monat netto';
        $answer = self::columnAnswer($wq, $bq);
        [, $p] = $this->read('invoice', self::netPages([$wq, $bq, 'Summe netto 1.447,60 €', 'Umsatzsteuer 19 % auf 1.447,60 € 275,04 €']), $answer, 'Jahresabrechnung', 'l1');
        $f = $p['entry']['extraction']['fields'];
        self::assertSame(['ok', 'ok', 'line'], [$f['working_prices']['items'][0]['status'], $f['base_price']['status'], $f['working_prices']['items'][0]['vat_read']['from'] ?? null],
            'genau ein Satz auf der Rechnung: gelesen');
        // 7 % und 19 % im selben Zeitraum: nichts umgerechnet, die Marke bleibt („unmarkiert falsch“ steigt nicht)
        [, $p] = $this->read('invoice', self::netPages([$wq, $bq, 'Umsatzsteuer 7 % auf 500,00 € 35,00 €', 'Umsatzsteuer 19 % auf 947,60 € 180,04 €']), $answer, 'Jahresabrechnung', 'l2');
        $f = $p['entry']['extraction']['fields'];
        self::assertSame(['check', 'check'], [$f['working_prices']['items'][0]['status'], $f['base_price']['status']]);
        self::assertArrayNotHasKey('vat_read', $f['working_prices']['items'][0]);
        $wp = self::byKind($p['proposals'], 'contract_price')[0];
        self::assertSame([10.0, true, false], [$wp['data']['value'], $wp['check'], $wp['checked']], 'wie gedruckt vorgeschlagen, markiert, nicht angehakt');
    }

    // ── Nachbesserung 4: Satz in der Zeile des Zitats, Stände je Zähler, Datum und Titel ──

    public function testARateRightAfterTheQuoteInTheSameLineConvertsOneLineBelowNot(): void
    {
        $this->grossContract();
        $wq = 'Arbeitspreis ab 01.01.2025 13.216 kWh 10,00 ct/kWh';
        $answer = self::netAnswer([['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq, 'page' => 2]]);
        [, $p] = $this->read('invoice', self::netPages([$wq . ' 19% 1.321,60 €']), $answer, 'Jahresabrechnung', 'r1');
        $it = $p['entry']['extraction']['fields']['working_prices']['items'][0];
        self::assertSame(['ok', 'row'], [$it['status'], $it['vat_read']['from'] ?? null], 'Zitat endet direkt vor „19%“: gelesen');
        self::assertContains('price_matches', $it['reasons']);
        [, $p] = $this->read('invoice', self::netPages([$wq, '19% 1.321,60 €']), $answer, 'Jahresabrechnung', 'r2');
        self::assertSame('check', $p['entry']['extraction']['fields']['working_prices']['items'][0]['status'], 'eine Zeile tiefer: nicht');
    }

    /** Erfundene Jahresabrechnung mit Zählertausch im Zeitraum: zwei Zähler, je Anfangs- und Endstand. */
    private static function swapPages(string $oldEnd = '14.05.2025', string $final = '12.910'): array
    {
        $p = InboxFixture::invoicePages();
        $p[0] = str_replace("Zählerstand alt am 31.12.2024: 12.345 m³\nZählerstand neu am 31.12.2025: 13.580 m³\n",
            "Zähler TEST-4711-01        Anfangsstand 12.345 m³ am 31.12.2024        Endstand $final m³ am $oldEnd (Ausbau)\n"
            . "Zähler TEST-9999-02        Anfangsstand 0,5 m³ am 14.05.2025 (Einbau)        Endstand 702,3 m³ am 31.12.2025\n", $p[0]);
        return $p;
    }

    private static function swapAnswer(string $oldEnd = '14.05.2025', string $final = '12.910'): array
    {
        $a = InboxFixture::invoiceAnswer();
        $q1 = "Zähler TEST-4711-01 Anfangsstand 12.345 m³ am 31.12.2024 Endstand $final m³ am $oldEnd";
        $q2 = 'Zähler TEST-9999-02 Anfangsstand 0,5 m³ am 14.05.2025 (Einbau) Endstand 702,3 m³ am 31.12.2025';
        // die Einzelfelder wie bisher: ein Stand je Rechnung (hier Anfang des alten, Ende des neuen Zählers)
        $a['reading_old'] = ['value' => '12.345', 'quote' => 'Anfangsstand 12.345 m³ am 31.12.2024', 'page' => 1];
        $a['reading_old_date'] = ['value' => '31.12.2024', 'quote' => 'Anfangsstand 12.345 m³ am 31.12.2024', 'page' => 1];
        $a['reading_new'] = ['value' => '702,3', 'quote' => 'Endstand 702,3 m³ am 31.12.2025', 'page' => 1];
        $a['reading_new_date'] = ['value' => '31.12.2025', 'quote' => 'Endstand 702,3 m³ am 31.12.2025', 'page' => 1];
        $a['meter_readings'] = ['value' => [
            ['meter_number' => 'TEST-4711-01', 'reading_old' => '12.345', 'reading_old_date' => '31.12.2024', 'reading_new' => $final, 'reading_new_date' => $oldEnd, 'quote' => $q1, 'page' => 1],
            ['meter_number' => 'TEST-9999-02', 'reading_old' => '0,5', 'reading_old_date' => '14.05.2025', 'reading_new' => '702,3', 'reading_new_date' => '31.12.2025', 'quote' => $q2, 'page' => 1],
        ]];
        return $a;
    }

    public function testAMeterSwapOnTheBillIsProposedWithBothReadingsAndPreselectedWhenItFits(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899']);
        [$s, $p] = $this->read('invoice', self::swapPages(), self::swapAnswer(), 'Jahresabrechnung', 'sw');
        $swap = self::byKind($p['proposals'], 'meter_change');
        self::assertCount(1, $swap);
        self::assertSame(['new', true, ['meter_id' => 'm_gas_default', 'date' => '2025-05-14', 'old_final_counter' => 12910.0, 'new_initial_counter' => 0.5,
            'serial' => 'TEST-9999-02', 'reason' => 'inbox']], [$swap[0]['status'], $swap[0]['checked'], $swap[0]['data']]);
        self::assertSame('inbox.proposal.meterSwapInvoice', $swap[0]['label_key']);
        $readings = self::byKind($p['proposals'], 'reading');
        self::assertCount(2, $readings, 'kein Stand doppelt (Einzelfelder und Stände je Zähler)');
        $src = array_column(array_map(fn($r) => [$r['data']['date'], $r['sources']], $readings), 1, 0);
        self::assertSame([['reading_new', 'reading_new_date'], ['meter_readings']], [$src['2025-12-31'] ?? null, $src['2024-12-31'] ?? null],
            'gleich: der Vorschlag der Einzelfelder bleibt; der Anfangsstand passt erst mit dem Endstand desselben Zählers — dann gilt der aus den Ständen je Zähler');
        $by = array_column(array_map(fn($r) => [$r['data']['date'], [$r['data']['counter'], $r['checked']]], $readings), 1, 0);
        ksort($by);
        self::assertSame(['2024-12-31' => [12345.0, true], '2025-12-31' => [702.3, true]], $by, 'Anfang des alten und Ende des neuen Zählers, keine Ablesung am Tauschtag');
        $s->apply($p['entry']['id'], array_column(array_filter($p['proposals'], fn($x) => $x['checked']), 'id'));
        $m = $this->meters->get('gas', 'm_gas_default');
        self::assertSame([['TEST-4711-01', '2025-05-14', 12910.0], ['TEST-9999-02', null, null]],
            array_map(fn($d) => [$d['serial'], $d['removed_on'], $d['final_counter'] === null ? null : (float)$d['final_counter']], $m['devices']));
        $dev = array_column(array_map(fn($r) => [$r['date'], $r['device_id']], $this->readings->list('gas', 'm_gas_default')), 1, 0);
        self::assertSame([$m['devices'][0]['id'], $m['devices'][1]['id']], [$dev['2024-12-31'] ?? null, $dev['2025-12-31'] ?? null], 'jeder Stand am richtigen Gerät');
    }

    public function testOnTheSwapDayTheSwapCarriesTheReadingsNotAReading(): void
    {
        // das Modell setzt den Endstand des alten Zählers auch in die Einzelfelder (ein Stand je Rechnung)
        $this->gasMeter();
        $a = self::swapAnswer();
        $a['reading_new'] = ['value' => '12.910', 'quote' => 'Endstand 12.910 m³ am 14.05.2025', 'page' => 1];
        $a['reading_new_date'] = ['value' => '14.05.2025', 'quote' => 'Endstand 12.910 m³ am 14.05.2025', 'page' => 1];
        [, $p] = $this->read('invoice', self::swapPages(), $a, 'Jahresabrechnung', 'sd');
        self::assertSame([], array_values(array_filter(self::byKind($p['proposals'], 'reading'), fn($r) => $r['data']['date'] === '2025-05-14')),
            'am Tauschtag gehörte eine Ablesung zum neuen Gerät — der Endstand des alten steht am Tausch');
        self::assertTrue(self::byKind($p['proposals'], 'meter_change')[0]['checked']);
    }

    public function testAMeterSwapThatDoesNotFitIsOnlyOffered(): void
    {
        $this->gasMeter();
        // Ausbau vier Tage vor dem Einbau: Tag passt nicht
        [, $p] = $this->read('invoice', self::swapPages('10.05.2025'), self::swapAnswer('10.05.2025'), 'Jahresabrechnung', 's1');
        $swap = self::byKind($p['proposals'], 'meter_change')[0];
        self::assertSame(['new', false], [$swap['status'], $swap['checked']], 'angeboten, nicht vorbelegt');
        // Endstand des alten unter seinem Anfangsstand
        [, $p] = $this->read('invoice', self::swapPages('14.05.2025', '12.000'), self::swapAnswer('14.05.2025', '12.000'), 'Jahresabrechnung', 's3');
        self::assertFalse(self::byKind($p['proposals'], 'meter_change')[0]['checked'], 'Endstand kleiner als der Anfangsstand des alten');
        // Endstand des neuen unter seinem Anfangsstand
        $a = self::swapAnswer();
        $a['meter_readings']['value'][1]['reading_new'] = '0,3';
        $a['meter_readings']['value'][1]['quote'] = 'Zähler TEST-9999-02 Anfangsstand 0,5 m³ am 14.05.2025 (Einbau) Endstand 0,3 m³ am 31.12.2025';
        $pages = self::swapPages();
        $pages[0] = str_replace('Endstand 702,3 m³ am 31.12.2025', 'Endstand 0,3 m³ am 31.12.2025', $pages[0]);
        [, $p] = $this->read('invoice', $pages, $a, 'Jahresabrechnung', 's4');
        self::assertFalse(self::byKind($p['proposals'], 'meter_change')[0]['checked'], 'Endstand kleiner als der Anfangsstand des neuen');
        // schon ein Stand nach dem Tauschtag: zu welchem Gerät, bliebe offen
        $r = $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2025-06-01', 'counter' => 5]);   // schon vom neuen Zähler?
        [, $p] = $this->read('invoice', self::swapPages(), self::swapAnswer(), 'Jahresabrechnung', 's5');
        self::assertFalse(self::byKind($p['proposals'], 'meter_change')[0]['checked'], 'Stand ab dem Tauschtag');
        $this->readings->delete('gas', (string)$r['id']);
        // ein bekannter Stand des alten Zählers über dem Endstand
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2025-03-01', 'counter' => 12950]);
        [, $p] = $this->read('invoice', self::swapPages(), self::swapAnswer(), 'Jahresabrechnung', 's2');
        self::assertFalse(self::byKind($p['proposals'], 'meter_change')[0]['checked'], 'Endstand kleiner als ein bekannter Stand');
    }

    // ── Nachbesserung 5: Tausch mit Zeiträumen je Zähler, Nummer als Kopfzeile ──

    /**
     * Erfundene Rechnung: alter Zähler ein Zeitraum, neuer Zähler drei, die Nummer je
     * Zähler als Kopfzeile über seinen Standzeilen (nicht in der Zeile selbst).
     */
    private static function periodPages(string $oldEnd = '14.05.2025'): array
    {
        $p = InboxFixture::invoicePages();
        $p[0] = str_replace("Zählerstand alt am 31.12.2024: 12.345 m³\nZählerstand neu am 31.12.2025: 13.580 m³\n",
            "Zähler TEST-4711-01\n31.12.2024        12.345 m³        $oldEnd        12.910 m³        Ausbau\n"
            . "Zähler TEST-9999-02\n14.05.2025        0,5 m³        30.06.2025        150,2 m³\n"
            . "30.06.2025        150,2 m³        30.09.2025        400,4 m³\n30.09.2025        400,4 m³        31.12.2025        702,3 m³\n", $p[0]);
        return $p;
    }

    private static function periodAnswer(string $oldEnd = '14.05.2025'): array
    {
        $a = self::swapAnswer($oldEnd);
        $row = fn(string $n, string $d1, string $c1, string $d2, string $c2) => ['meter_number' => $n, 'reading_old' => $c1, 'reading_old_date' => $d1,
            'reading_new' => $c2, 'reading_new_date' => $d2, 'quote' => "$d1 $c1 m³ $d2 $c2 m³", 'page' => 1];
        $a['reading_old'] = ['value' => '12.345', 'quote' => '31.12.2024 12.345 m³', 'page' => 1];
        $a['reading_old_date'] = ['value' => '31.12.2024', 'quote' => '31.12.2024 12.345 m³', 'page' => 1];
        $a['reading_new'] = ['value' => '702,3', 'quote' => '31.12.2025 702,3 m³', 'page' => 1];
        $a['reading_new_date'] = ['value' => '31.12.2025', 'quote' => '31.12.2025 702,3 m³', 'page' => 1];
        $a['meter_readings'] = ['value' => [
            $row('TEST-4711-01', '31.12.2024', '12.345', $oldEnd, '12.910'),
            $row('TEST-9999-02', '14.05.2025', '0,5', '30.06.2025', '150,2'),
            $row('TEST-9999-02', '30.06.2025', '150,2', '30.09.2025', '400,4'),
            $row('TEST-9999-02', '30.09.2025', '400,4', '31.12.2025', '702,3'),
        ]];
        return $a;
    }

    public function testEntriesOfOneMeterAreMergedAndTheSwapPutsEveryReadingAtTheRightDevice(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899']);
        [$s, $p] = $this->read('invoice', self::periodPages(), self::periodAnswer(), 'Jahresabrechnung', 'pm');
        $mr = $p['entry']['extraction']['fields']['meter_readings'];
        self::assertCount(2, $mr['items'], 'drei Zeiträume des neuen Zählers: ein Eintrag');
        self::assertSame(['TEST-9999-02', 0.5, '2025-05-14', 702.3, '2025-12-31'], [$mr['items'][1]['value']['meter_number'], $mr['items'][1]['value']['reading_old'],
            $mr['items'][1]['value']['reading_old_date'], $mr['items'][1]['value']['reading_new'], $mr['items'][1]['value']['reading_new_date']]);
        self::assertSame(['meter_number', 'reading_old', 'reading_old_date', 'reading_new', 'reading_new_date', 'estimated'], array_keys($mr['items'][1]['value']), 'Form des Eintrags bleibt');
        self::assertSame([['2025-06-30', 150.2], ['2025-09-30', 400.4]], array_map(fn($b) => [$b['date'], $b['counter']], $mr['items'][1]['between']));
        self::assertSame([3, 'ok', 'ok', 'header', 'header'], [$mr['items'][1]['merged'], $mr['items'][0]['status'], $mr['items'][1]['status'],
            $mr['items'][0]['number_from'] ?? null, $mr['items'][1]['number_from'] ?? null], 'die Nummer in der Kopfzeile darüber belegt den Eintrag');
        $swap = self::byKind($p['proposals'], 'meter_change');
        self::assertSame([1, true, 12910.0, 0.5], [count($swap), $swap[0]['checked'], $swap[0]['data']['old_final_counter'], $swap[0]['data']['new_initial_counter']]);
        $by = array_column(array_map(fn($r) => [$r['data']['date'], [$r['data']['counter'], $r['checked']]], self::byKind($p['proposals'], 'reading')), 1, 0);
        ksort($by);
        self::assertSame(['2024-12-31' => [12345.0, true], '2025-06-30' => [150.2, true], '2025-09-30' => [400.4, true], '2025-12-31' => [702.3, true]], $by,
            'Anfang des alten, Zwischenstände und Ende des neuen Zählers; keine Ablesung am Tauschtag');
        $s->apply($p['entry']['id'], array_column(array_filter($p['proposals'], fn($x) => $x['checked']), 'id'));
        $m = $this->meters->get('gas', 'm_gas_default');
        $dev = array_column(array_map(fn($r) => [$r['date'], $r['device_id']], $this->readings->list('gas', 'm_gas_default')), 1, 0);
        self::assertSame([$m['devices'][0]['id'], $m['devices'][1]['id'], $m['devices'][1]['id'], $m['devices'][1]['id']],
            [$dev['2024-12-31'] ?? null, $dev['2025-06-30'] ?? null, $dev['2025-09-30'] ?? null, $dev['2025-12-31'] ?? null], 'jeder Stand am richtigen Gerät');
    }

    public function testWithoutAPreselectedSwapNoReadingOfTheNewMeterIsPreselected(): void
    {
        // Ausbau vier Tage vor dem Einbau: Tausch nur angeboten — dann landete jeder Stand des neuen Zählers am alten Gerät
        $this->gasMeter();
        [, $p] = $this->read('invoice', self::periodPages('10.05.2025'), self::periodAnswer('10.05.2025'), 'Jahresabrechnung', 'pn');
        self::assertFalse(self::byKind($p['proposals'], 'meter_change')[0]['checked']);
        $checked = array_map(fn($r) => $r['data']['date'], array_filter(self::byKind($p['proposals'], 'reading'), fn($r) => $r['checked']));
        self::assertSame(['2024-12-31'], array_values($checked), 'nur der Anfangsstand des alten Zählers; der Endstand des neuen (auch aus den Einzelfeldern) nicht');
        // mehrere Nummern ohne Paar: kein Stand der Einzelfelder vorbelegt, den kein Eintrag dieses Geräts trägt
        $a = self::periodAnswer();
        $a['meter_readings']['value'][] = ['meter_number' => 'TEST-5555-03', 'reading_old' => '1', 'reading_old_date' => '31.12.2024', 'reading_new' => '702,3',
            'reading_new_date' => '31.12.2025', 'quote' => '30.09.2025 400,4 m³ 31.12.2025 702,3 m³', 'page' => 1];
        [, $p] = $this->read('invoice', self::periodPages(), $a, 'Jahresabrechnung', 'p3');
        self::assertSame([], self::byKind($p['proposals'], 'meter_change'), 'drei Nummern: kein Paar');
        $checked = array_map(fn($r) => $r['data']['date'], array_filter(self::byKind($p['proposals'], 'reading'), fn($r) => $r['checked']));
        sort($checked);
        self::assertSame(['2024-12-31', '2025-05-14'], $checked, 'die Stände des alten Zählers (ohne Tausch an seinem Gerät richtig), keiner des neuen');
        self::assertFalse(array_values(array_filter(self::byKind($p['proposals'], 'reading'), fn($r) => $r['data']['date'] === '2025-12-31'))[0]['checked'],
            'Endstand der Einzelfelder: kein Eintrag dieses Geräts trägt ihn');
    }

    public function testANumberInTheHeaderOfAnotherMeterDoesNotCount(): void
    {
        // das Modell setzt die Nummer des neuen Zählers an die Zeile des alten: Kopfzeile darüber ist eine andere
        $a = self::periodAnswer();
        $a['meter_readings']['value'][0]['meter_number'] = 'TEST-9999-02';
        $f = Evidence::check('invoice', $a, self::periodPages());
        $wrong = array_values(array_filter($f['meter_readings']['items'], fn($it) => ($it['value']['reading_old_date'] ?? '') === '2024-12-31'));
        self::assertSame('check', $wrong[0]['status']);
        self::assertContains('value_not_in_quote', $wrong[0]['reasons']);
    }

    public function testASwapIsPreselectedOnlyForTheDeviceThatRanUntilThen(): void
    {
        // der Zähler hatte schon einen Tausch: TEST-4711-01 lief bis 2022, seither ALT-ZWEI
        $this->gasMeter();
        $this->meters->replaceDevice('gas', 'm_gas_default', ['date' => '2022-06-01', 'old_final_counter' => 9000, 'new_initial_counter' => 0, 'serial' => 'ALT-ZWEI']);
        [, $p] = $this->read('invoice', self::swapPages(), self::swapAnswer(), 'Jahresabrechnung', 'dv');
        $swap = self::byKind($p['proposals'], 'meter_change')[0];
        self::assertSame(['new', false], [$swap['status'], $swap['checked']], 'die Nummer passt zu einem Gerät, aber nicht zu dem, das bis zum Tausch lief');
        // ein Stand dieses Eintrags gehörte 2024 zu ALT-ZWEI: markiert, nicht vorbelegt
        $r = array_values(array_filter(self::byKind($p['proposals'], 'reading'), fn($x) => $x['data']['date'] === '2024-12-31'))[0] ?? null;
        self::assertSame([true, false], [$r['check'] ?? null, $r['checked'] ?? null], json_encode($r['reasons'] ?? null));
    }

    public function testAForeignMeterWithoutItsPairOrTwiceTheSameNumberProposesNothing(): void
    {
        $this->gasMeter();
        $a = self::swapAnswer();
        $a['meter_readings']['value'] = [$a['meter_readings']['value'][1]];   // nur der neue Zähler, Endstand des alten fehlt
        [, $p] = $this->read('invoice', self::swapPages(), $a, 'Jahresabrechnung', 'f1');
        self::assertSame([], self::byKind($p['proposals'], 'meter_change'), 'ohne Endstand des alten kein Tausch');
        self::assertSame([], array_values(array_filter(self::byKind($p['proposals'], 'reading'), fn($r) => $r['sources'] === ['meter_readings'])),
            'und keine Stände des fremden Zählers');
        $a = self::swapAnswer();
        $a['meter_readings']['value'][1]['meter_number'] = 'TEST-4711-01';   // Zweitarifzähler: zweimal dieselbe Nummer
        [, $p] = $this->read('invoice', self::swapPages(), $a, 'Jahresabrechnung', 'f2');
        self::assertSame([[], []], [self::byKind($p['proposals'], 'meter_change'),
            array_values(array_filter(self::byKind($p['proposals'], 'reading'), fn($r) => $r['sources'] === ['meter_readings']))]);
    }

    public function testAnEstimatedReadingOnTheBillIsTakenOverAsEstimated(): void
    {
        // erfunden: „S“ hinter dem Endstand, Legende unten auf der Seite
        $this->gasMeter();
        $pages = InboxFixture::invoicePages();
        $pages[0] = str_replace('Zählerstand neu am 31.12.2025: 13.580 m³', 'Zählerstand neu am 31.12.2025: 13.580 m³ S', $pages[0]) . "\nAblesung: A = abgelesen, S = geschätzt";
        $a = InboxFixture::invoiceAnswer();
        $a['reading_new']['quote'] = $a['reading_new_date']['quote'] = 'Zählerstand neu am 31.12.2025: 13.580 m³ S';
        $a['reading_new_estimated'] = ['value' => true, 'quote' => 'S', 'page' => 1];
        [$s, $p] = $this->read('invoice', $pages, $a, 'Jahresabrechnung', 'es');
        $byDate = array_column(array_map(fn($r) => [$r['data']['date'], $r], self::byKind($p['proposals'], 'reading')), 1, 0);
        self::assertSame([true, 'inbox.proposal.readingEstimated'], [$byDate['2025-12-31']['data']['is_estimated'] ?? null, $byDate['2025-12-31']['label_key']]);
        self::assertArrayNotHasKey('is_estimated', $byDate['2024-12-31']['data'], 'der Anfangsstand ist abgelesen');
        $s->apply($p['entry']['id'], [$byDate['2025-12-31']['id'], $byDate['2024-12-31']['id']]);
        $est = array_column(array_map(fn($r) => [$r['date'], $r['is_estimated']], $this->readings->list('gas', 'm_gas_default')), 1, 0);
        self::assertSame([true, false], [$est['2025-12-31'] ?? null, $est['2024-12-31'] ?? null], 'als geschätzt angelegt: zählt in der Monatskennung nicht als Messung');
        // ohne Legende auf dem Dokument: nicht geschätzt
        $pages[0] = str_replace("\nAblesung: A = abgelesen, S = geschätzt", '', $pages[0]);
        [, $p] = $this->read('invoice', $pages, $a, 'Jahresabrechnung', 'en');
        $r = array_values(array_filter(self::byKind($p['proposals'], 'reading'), fn($x) => $x['data']['date'] === '2025-12-31'))[0];
        self::assertArrayNotHasKey('is_estimated', $r['data']);
        // Stände je Zähler: „R = rechnerisch“ am Eintrag gilt auch für den gleichen Vorschlag der Einzelfelder
        // (Nachbesserung 5: das „R“ steht vor den Ständen — hinter dem Endstand läse es schon das Einzelfeld selbst)
        $b = self::swapAnswer();
        $pp = self::swapPages();
        $pp[0] = str_replace('Zähler TEST-9999-02        Anfangsstand', 'Zähler TEST-9999-02 R        Anfangsstand', $pp[0]) . "\nR = rechnerisch ermittelt";
        $b['meter_readings']['value'][1]['quote'] = str_replace('TEST-9999-02 Anfangsstand', 'TEST-9999-02 R Anfangsstand', $b['meter_readings']['value'][1]['quote']);
        [, $p] = $this->read('invoice', $pp, $b, 'Jahresabrechnung', 'er');
        $r = array_values(array_filter(self::byKind($p['proposals'], 'reading'), fn($x) => $x['data']['date'] === '2025-12-31'))[0];
        self::assertSame([true, ['reading_new', 'reading_new_date']], [$r['data']['is_estimated'] ?? null, $r['sources']]);
    }

    public function testACorrectionCarriesItsTitleAndALaterDate(): void
    {
        $this->gasMeter();
        $f = fn($v, string $q, int $page = 1) => ['value' => $v, 'quote' => $q, 'page' => $page];
        $a = InboxFixture::invoiceAnswer() + ['document_title' => $f('Jahresabrechnung', 'Jahresabrechnung Erdgas 2025'),
            'document_date' => $f('10.02.2026', 'Musterstadt, den 10.02.2026')];
        $pages = InboxFixture::invoicePages();
        $pages[0] = str_replace("Jahresabrechnung Erdgas 2025\n", "Jahresabrechnung Erdgas 2025\nMusterstadt, den 10.02.2026\n", $pages[0]);
        [, $first] = $this->read('invoice', $pages, $a, 'Jahresabrechnung', 'k1');
        $b = $a; $b['document_title'] = $f('Rechnungskorrektur', 'Rechnungskorrektur · Seite 1 von 3'); $b['document_date'] = $f('20.03.2026', 'Musterstadt, den 20.03.2026');
        $b['total_amount'] = $f('1.702,70', 'Rechnungsbetrag brutto: 1.702,70 €');
        $pages2 = $pages;
        $pages2[0] = "Rechnungskorrektur · Seite 1 von 3\n" . str_replace(['10.02.2026', '1.722,70'], ['20.03.2026', '1.702,70'], $pages[0]);
        [, $second] = $this->read('invoice', $pages2, $b, 'Jahresabrechnung', 'k2');
        $x = fn(array $pv, string $n) => [$pv['entry']['extraction']['fields'][$n]['status'], $pv['entry']['extraction']['fields'][$n]['value']];
        self::assertSame([['ok', 'Jahresabrechnung'], ['ok', '2026-02-10']], [$x($first, 'document_title'), $x($first, 'document_date')]);
        self::assertSame([['ok', 'Rechnungskorrektur'], ['ok', '2026-03-20']], [$x($second, 'document_title'), $x($second, 'document_date')], 'Titel nur im Seitenkopf: gelesen');
        self::assertGreaterThan($x($first, 'document_date')[1], $x($second, 'document_date')[1]);
        self::assertSame([], $second['duplicates'] ?? [], 'eine Korrektur mit anderem Betrag ist kein Doppel');
    }

    public function testReadingAgainWithoutFreshUsesTheCacheAndChecksTheSentTextLayer(): void
    {
        // gespeicherte Auslesung (Schema 2) vor dieser Regel: ohne Modellaufruf neu geprüft, mit der Textebene der Anfrage
        $this->grossContract();
        $wq = (string)preg_replace('/\s+/', ' ', self::TAX_WP);
        $bq = (string)preg_replace('/\s+/', ' ', self::TAX_BP);
        $pages = self::netPages([self::TAX_HEAD, self::TAX_WP, self::TAX_BP]);
        [$s, $p] = $this->read('invoice', $pages, self::columnAnswer($wq, $bq), 'Jahresabrechnung', 'rc');
        $id = $p['entry']['id'];
        $all = $this->store->read('inbox.json', []);
        unset($all[0]['extraction']['fields']['working_prices']['items'][0]['vat_read'], $all[0]['extraction']['fields']['base_price']['vat_read']);
        $this->store->write('inbox.json', $all);
        self::assertSame('check', $s->preview($id)['entry']['extraction']['fields']['working_prices']['items'][0]['status'], 'gespeichert ohne den gelesenen Satz');
        $s->extract($id, $pages);
        $x = $this->store->read('inbox.json', [])[0]['extraction'];
        self::assertTrue($x['cached'], 'Zwischenspeicher getroffen: kein Modellaufruf');
        self::assertEquals(['ok', 19], [$x['fields']['working_prices']['items'][0]['status'], $x['fields']['working_prices']['items'][0]['vat_read']['vat_pct'] ?? null]);
    }

    public function testWithoutTheContractsRateTheVatChangeIsNotGuessed(): void
    {
        $this->gasMeter();
        // Vertrag von Hand (ohne Dokument): sein Steuersatz ist nirgends gelesen
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899', 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.77]]]);
        $wq = 'Arbeitspreis ab 01.01.2025: 11,00 ct/kWh netto, USt 19 %';
        [$fields] = $this->netRead([$wq], [['from' => '01.01.2025', 'ct' => '11,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq, 'page' => 2]], null, 'g1');
        self::assertSame('check', $fields['working_prices']['items'][0]['status'], 'kein Satz des Vertrags gelesen: nicht vermutet');
        self::assertNotContains('vat_changed', $fields['working_prices']['items'][0]['reasons']);
        // dieselbe Rechnung weist beide Sätze aus: der Satz des Vertrags steht in ihr
        $q7 = 'Arbeitspreis ab 01.01.2024: 11,00 ct/kWh netto, USt 7 %';
        [$fields, $props] = $this->netRead([$q7, $wq], [['from' => '01.01.2024', 'ct' => '11,00', 'basis' => 'net', 'vat_pct' => '7', 'quote' => $q7, 'page' => 2],
            ['from' => '01.01.2025', 'ct' => '11,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq, 'page' => 2]], null, 'g2');
        self::assertSame(['ok', 'ok'], array_column($fields['working_prices']['items'], 'status'));
        self::assertContains('price_matches', $fields['working_prices']['items'][0]['reasons'], '11,00 + 7 % = 11,77 wie im Vertrag');
        self::assertContains('vat_changed', $fields['working_prices']['items'][1]['reasons']);
        self::assertSame(['exists', 'new'], array_column($props, 'status'));
    }

    public function testARateIsOnlyReadFromWhatStandsOnTheDocument(): void
    {
        // Nachbesserung 5 — ein Satz nur aus einem gefundenen Zitat bzw. seinen gefundenen Teilen
        $this->grossContract();
        $line = 'Arbeitspreis ab 01.01.2025: 10,00 ct/kWh netto';
        $item = fn(string $q) => [['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $q, 'page' => 2]];
        // das Modell zitiert „zzgl. 19 % USt“, das so nicht dasteht
        [$fields] = $this->netRead([$line], $item("$line zzgl. 19 % USt"), null, 'fq1');
        $it = $fields['working_prices']['items'][0];
        self::assertSame('check', $it['status']);
        self::assertArrayNotHasKey('net', $it, 'kein Satz aus einem Zitat, das nicht dasteht');
        // mit „…“: gefunden ist nur der erste Teil, der Satz stünde im zweiten
        [$fields] = $this->netRead([$line, 'Stand: Januar 2025'], $item("$line … zzgl. 19 % USt"), null, 'fq2');
        self::assertArrayNotHasKey('net', $fields['working_prices']['items'][0], 'kein Satz aus dem Teil, der fehlt');
        self::assertSame('check', $fields['working_prices']['items'][0]['status']);
        // umgekehrt: der Satz steht im gefundenen Teil, ein anderer Teil fehlt — gelesen, die Marke bleibt
        [$fields] = $this->netRead(["$line zzgl. 19 % USt"], $item("$line zzgl. 19 % USt … Sonderbonus Treue"), null, 'fq4');
        self::assertSame(['check', 19.0], [$fields['working_prices']['items'][0]['status'], (float)($fields['working_prices']['items'][0]['net']['vat_pct'] ?? 0)],
            'aus dem gefundenen Teil');
        // ebenso am Grundpreis (Einzelfeld)
        $bl = 'Grundpreis: 10,50 € pro Monat netto zzgl. 19 % USt';
        $bf = fn($v) => ['value' => $v, 'quote' => "$bl … Treuerabatt", 'page' => 2];
        [$fields] = $this->netRead(["$line zzgl. 19 % USt", $bl], $item("$line zzgl. 19 % USt"), [$bf('10,50'), $bf('net'), $bf('19')], 'fq5');
        self::assertSame(['check', 19.0], [$fields['base_price']['status'], (float)($fields['base_price']['net']['vat_pct'] ?? 0)], 'Grundpreis: aus dem gefundenen Teil');
        // Gegenprobe: steht er da, rechnet die App um
        [$fields] = $this->netRead(["$line zzgl. 19 % USt"], $item("$line zzgl. 19 % USt"), null, 'fq3');
        self::assertEquals(['vat_pct' => 19, 'gross' => 11.9], $fields['working_prices']['items'][0]['net'] ?? null);
    }

    public function testTheBalanceStaysGrossAfterANetInvoice(): void
    {
        // Saldo und Prognose rechnen weiter brutto: Der Vertrag bekommt den Bruttopreis, die Rechnung bleibt, wie sie ist
        $this->gasMeter();
        $c = $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 12.5]]]);
        $wq = 'Arbeitspreis ab 01.01.2025: 10,00 ct/kWh netto zzgl. 19 % USt';
        [$s, $pv] = $this->read('invoice', self::netPages([$wq]), self::netAnswer([['from' => '01.01.2025', 'ct' => '10,00', 'basis' => 'net', 'vat_pct' => '19', 'quote' => $wq, 'page' => 2]]),
            'Jahresabrechnung', 'b1');
        $s->apply($pv['entry']['id'], array_column(array_filter($pv['proposals'], fn($p) => $p['kind'] === 'contract_price'), 'id'));
        self::assertSame([['from' => '2025-01-01', 'ct_per_kwh' => 11.9]], $this->contracts->get('gas', (string)$c['id'])['working_prices'], 'brutto im Vertrag');
    }

    // ── Doku hält Schritt (Lektion 31) ───────────────────────────────────

    public function testTheApiReferenceNamesEveryHintAndTheNewFields(): void
    {
        foreach (['docs/referenz/api.md', 'docs/en/referenz/api.md'] as $f) {
            $doc = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $f);
            foreach (Evidence::HINTS as $r) self::assertStringContainsString("`$r`", $doc, "$f: Hinweis $r");
            // das Urteil „keine Preise“ steht in der Zeile `verdict` von „Rechnung prüfen“ (nicht nur irgendwo)
            self::assertMatchesRegularExpression('/^\| `verdict` \|[^\n]*`no_prices`/m', $doc, "$f: verdict no_prices");
            foreach (['no_prices', 'factor_mismatch', 'boundaries', 'price_missing_at', 'factor_mismatches', 'duplicates', 'duplicate_of', 'factors',
                      'contractFromInvoice', 'contractFromSupplyStart', 'reading_adjacent',
                      // Nachbesserung 2: Netto und Steuersatz, Version des Schemas (dieselbe Zahl wie im Code)
                      '`basis: net`', '`vat_pct`', '`base_price_basis`', '`base_price_vat_pct`', '`net: {vat_pct, gross}`',
                      '`schema_version` **' . InboxSchema::VERSION . '**'] as $k) {
                self::assertStringContainsString($k, $doc, "$f: $k");
            }
            // der Abstand der Teile eines Zitats (mit „…“ und ohne): dieselbe Zahl Textzeilen wie im Code (Nachbesserung 5)
            $gap = (new \ReflectionClassConstant(Evidence::class, 'SPLIT_LINES'))->getValue();
            foreach (str_contains($f, '/en/') ? ["at most $gap text lines below the previous one", "at most $gap text lines above it"]
                         : ["höchstens $gap Textzeilen unter dem vorigen", "höchstens $gap Textzeilen darüber"] as $k) {
                self::assertStringContainsString($k, (string)preg_replace('/\s+/u', ' ', $doc), "$f: Abstand $gap Zeilen (mit „…“ und ohne)");
            }
            // Nachbesserung 5: Zeiträume je Zähler, Nummer in der Kopfzeile, Fußnoten, abgebrochene Antwort
            foreach (['`between:', '`merged`', '`estimated_ends`', '`number_from: header`', '`word`/`code`/`mark`', '`errors.inbox.truncated`', '*EMH', '„Š“'] as $k) {
                self::assertStringContainsString(str_contains($f, '/en/') ? str_replace('„Š“', '“Š”', $k) : $k, (string)preg_replace('/\s+/u', ' ', $doc), "$f: $k");
            }
            // Nachbesserung 3: Form der MaLo, Satz aus Steuerspalte oder Umsatzsteuerzeile — die üblichen Sätze wie im Code
            $rates = array_map(fn(float $r) => (string)(int)$r, (new \ReflectionClassConstant(Evidence::class, 'USUAL_RATES'))->getValue());
            $list = implode(', ', array_slice($rates, 0, -1)) . (str_contains($f, '/en/') ? ' or ' : ' oder ') . end($rates);
            foreach (['`invalid_format`', '`vat_read: {vat_pct, from}`', '`from: column`', '`from: line`', $list, '§ 12', '§ 28', 'Identifikatoren in der Marktkommunikation',
                      // Nachbesserung 4: Satz in der Zeile des Zitats, Datum, Titel, Stände je Zähler, Tausch aus der Rechnung
                      '`from: row`', '`document_date`', '`document_title`', '`meter_readings`', '`inbox.proposal.meterSwapInvoice`', '`fresh`',
                      // Zusatz: geschätzte Stände
                      '`reading_new_estimated`', '`reading_old_estimated`', '`estimated_by`', '`is_estimated: true`', '`inbox.proposal.readingEstimated`'] as $k) {
                self::assertStringContainsString($k, (string)preg_replace('/\s+/u', ' ', $doc), "$f: $k");
            }
        }
        foreach (['docs/referenz/datenmodell.md', 'docs/en/referenz/datenmodell.md'] as $f) {
            $doc = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $f);
            self::assertStringContainsString('"schema_version": ' . InboxSchema::VERSION . ',', $doc, "$f: Beispiel mit der Version des Schemas");
            self::assertStringContainsString('|qwen3.8|' . InboxSchema::VERSION . '"', $doc, "$f: Schlüssel des Zwischenspeichers");
        }
        // die Anleitung nennt die Regel der Einheiten so, wie Evidence::utilityEvidence sie rechnet
        $flat = fn(string $f) => (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/' . $f));
        self::assertStringContainsString('m³ und kWh zusammen nur bei Gas', $flat('docs/anleitungen/posteingang.md'));
        self::assertStringContainsString('m³ and kWh together only for gas', $flat('docs/en/anleitungen/posteingang.md'));
        self::assertSame(['gas'], Evidence::utilityEvidence('stand 1.235 m³ · verbrauch 13.216 kwh'), 'm³ und kWh zusammen: nur Gas');
        self::assertSame(['gas', 'wasser'], Evidence::utilityEvidence('stand 120 m³'));
        self::assertSame(['strom'], Evidence::utilityEvidence('ökostrom fix · 2.500 kwh'));
        // Nachbesserung nach dem Nutzentest: die Anleitung nennt, wann Vorgängerende und alte Ablesung vorab angehakt sind
        foreach (['docs/anleitungen/posteingang.md' => ['der bisherige Vertrag endet am Vortag — vorab angehakt', 'alte Ablesung einer Rechnung ist vorab angehakt', 'kein Zählertausch dazwischen'],
                  'docs/en/anleitungen/posteingang.md' => ['the previous contract ends the day before — ticked in advance', 'old reading of a bill is ticked in advance', 'no meter swap in between'],
                  'docs/anleitungen/jahresabrechnung.md' => ['bleibt es „prüfen“, und der Hinweis auf die fehlenden Preise'],
                  'docs/en/anleitungen/jahresabrechnung.md' => ['it stays “check”, and the notice about the missing prices'],
                  'docs/referenz/api.md' => ['offQuantityNoPrices'], 'docs/en/referenz/api.md' => ['offQuantityNoPrices']] as $f => $needles) {
            foreach ($needles as $n) self::assertStringContainsString($n, $flat($f), "$f: $n");
        }
        // Nachbesserung 2: Nettopreise, anderer Steuersatz, Belegstelle aus mehreren Stellen, Umlaute
        foreach (['docs/anleitungen/posteingang.md' => ['Ein Steuersatz wird gelesen, nie vermutet', 'Gleicher Preis, anderer Steuersatz', 'Belegstelle aus mehreren Stellen', 'der Wert im zweiten und nicht im ersten', '„Ã¤“ oder „√§“ statt „ä“',
                      'Prüfziffer nach BDEW', 'aus einer Steuerspalte (Kopf „USt %“ über der Zeile', 'weist sie zwei Sätze aus, rechnet die App nicht um', 'in ihrer Reihenfolge nah beieinander',
                      'Eine frühere Auslesung behält ihre Prüfung der Zitate, bis du das Dokument neu ausliest',
                      'aus derselben Textzeile, wenn das Modell genau ihn nennt', 'ein **Zählertausch** mit Endstand des alten und Anfangsstand des neuen Zählers', '**Datum und Titel**',
                      'ein Ablesecode, den sie selbst erklärt, etwa „S = geschätzt“), wird er als geschätzt angelegt'],
                  'docs/en/anleitungen/posteingang.md' => ['A VAT rate is read, never guessed', 'Same price, different VAT rate', 'quote from several places', 'the value in the second and not in the first', '“Ã¤” or “√§” instead of “ä”',
                      'check digit as defined by the BDEW', 'from a tax column (header “USt %” above the row', 'if the bill shows two rates, the app does not convert', 'close together in their order',
                      'a reading made before keeps its quote check until you read the document again',
                      'from the same text line when the model names exactly that rate', 'a **meter swap** with the final reading of the old and the initial reading of the new meter', '**date and title**',
                      'a reading code it explains itself, such as “S = geschätzt”), it is created as estimated']] as $f => $needles) {
            foreach ($needles as $n) self::assertStringContainsString($n, $flat($f), "$f: $n");
        }
        // Nachbesserung 5: die Zahlen des Kontextfensters wie im Code, Zeiträume je Zähler, weitere Schätzzeichen
        $de = fn(int|float $n) => number_format($n, is_float($n) ? 1 : 0, ',', '.');
        $en = fn(int|float $n) => number_format($n, is_float($n) ? 1 : 0, '.', ',');
        $cpt = (new \ReflectionClassConstant(\Energietracker\Services\Inbox\LocalProvider::class, 'CHARS_PER_TOKEN'))->getValue();
        $L = \Energietracker\Services\Inbox\LocalProvider::class;
        foreach (['docs/anleitungen/posteingang.md' => [$de($cpt) . ' Zeichen je Token', 'dazu ' . $de($L::EXTRACT_TOKENS) . ' Token für die Antwort', 'höchstens ' . $de($L::CTX_MAX),
                      'mit doppeltem Antwortbudget (' . $de($L::EXTRACT_TOKENS_RETRY) . ' Token)', '**Stände je Zähler über mehrere Zeiträume**', 'als Kopfzeile über den Standzeilen',
                      'nur vorab angehakt, wenn der Tausch es ist', '„5) hochgerechnet“', 'alle beteiligten Beträge markiert'],
                  'docs/en/anleitungen/posteingang.md' => [$en($cpt) . ' characters per token', 'plus ' . $en($L::EXTRACT_TOKENS) . ' tokens for the answer', 'at most ' . $en($L::CTX_MAX),
                      'with the double answer budget (' . $en($L::EXTRACT_TOKENS_RETRY) . ' tokens)', '**Readings per meter over several periods**', 'as a header line above the reading lines',
                      'only ticked in advance when the swap is', '“5) hochgerechnet”', 'every amount involved is flagged']] as $f => $needles) {
            foreach ($needles as $n) self::assertStringContainsString($n, $flat($f), "$f: $n");
        }
        self::assertSame(2 * $L::EXTRACT_TOKENS, $L::EXTRACT_TOKENS_RETRY, '„doppeltes Antwortbudget“');
    }

    // ── 7. Ältere Auslesungen ────────────────────────────────────────────

    public function testMarksOfAnOlderReadingAreRecomputedAndStoredOnTheNextChange(): void
    {
        $this->gasMeter();
        $this->gasContract('Stadtwerke Musterstadt', ['contract_number' => 'VK-778899']);
        $a = InboxFixture::invoiceAnswer();
        unset($a['advance_new'], $a['advance_new_from']);
        [$s, $p] = $this->read('invoice', InboxFixture::invoicePages(), $a, 'Jahresabrechnung', 'ol');
        $id = $p['entry']['id'];
        // so hätte v3.7 vor diesem Paket gespeichert: leeres Kernfeld markiert
        $all = $this->store->read('inbox.json', []);
        $all[0]['extraction']['fields']['advance_new'] = ['value' => null, 'raw' => null, 'quote' => null, 'page' => null, 'status' => 'check', 'reasons' => ['empty']];
        $all[0]['extraction']['flags'] = 9;
        $this->store->write('inbox.json', $all);
        $pv = $s->preview($id);
        self::assertSame(['empty', ['missing']], [$pv['entry']['extraction']['fields']['advance_new']['status'], $pv['entry']['extraction']['fields']['advance_new']['reasons']],
            'die Vorschau rechnet nach heutiger Regel');
        self::assertSame(2, $pv['entry']['extraction']['flags'], 'nur die zwei Preise');
        self::assertSame(2, $s->list()[0]['flags'], 'die Liste auch');
        self::assertSame(9, $this->store->read('inbox.json', [])[0]['extraction']['flags'], 'gelesen, nicht geschrieben');
        $s->update($id, []);
        $stored = $this->store->read('inbox.json', [])[0]['extraction'];
        self::assertSame(['missing'], $stored['fields']['advance_new']['reasons'], 'ein Ändern speichert die Marken (extraction.fields.*.reasons)');
        self::assertSame(2, $stored['flags']);
    }

    public function testDateAndTitleOfAnOlderReadingLoseTheirMandatoryMark(): void
    {
        // Nachbesserung 5 — so hätte Version 3 gespeichert: Datum und Titel einer Ablesekarte pflichtgeprüft
        $pages = InboxFixture::readingRequestPages('31.01.2026');
        $pages[0] = "Ablesekarte · Musterstadt, den 02.01.2026\n" . $pages[0];
        $a = InboxFixture::readingRequestAnswer('31.01.2026') + ['document_date' => ['value' => '02.01.2026', 'quote' => 'Musterstadt, den 02.01.2026', 'page' => 1],
            'document_title' => ['value' => 'Ablesekarte', 'quote' => 'Ablesekarte', 'page' => 1]];
        [$s, $p] = $this->read('reading_request', $pages, $a, 'Zählerstand mitteilen', 'rr');
        self::assertSame(['ok', 'ok'], [$p['entry']['extraction']['fields']['document_date']['status'], $p['entry']['extraction']['fields']['document_title']['status']]);
        $all = $this->store->read('inbox.json', []);
        foreach (['document_date', 'document_title'] as $n) {
            $all[0]['extraction']['fields'][$n]['status'] = 'check';
            $all[0]['extraction']['fields'][$n]['reasons'] = ['mandatory'];
        }
        $this->store->write('inbox.json', $all);
        $pv = $s->preview($p['entry']['id']);
        foreach (['document_date', 'document_title'] as $n) {
            self::assertSame(['ok', []], [$pv['entry']['extraction']['fields'][$n]['status'], $pv['entry']['extraction']['fields'][$n]['reasons']], "$n: nach heutiger Regel ohne Marke");
        }
        self::assertSame(['check', ['mandatory']], [$pv['entry']['extraction']['fields']['requested_by']['status'], $pv['entry']['extraction']['fields']['requested_by']['reasons']],
            'die Frist bleibt pflichtgeprüft');
    }

    public function testAnOlderReadingKeepsItsQuoteCheckUntilReadAgain(): void
    {
        // Nachbesserung 2 — die Belegstelle prüft der Server beim Auslesen gegen die Textebene, die er nicht aufbewahrt
        $this->gasMeter();
        $a = InboxFixture::invoiceAnswer();
        $a['meter_number']['quote'] = 'Zählernummer … TEST-4711-01';
        [$s, $p] = $this->read('invoice', InboxFixture::invoicePages(), $a, 'Jahresabrechnung', 'oq');
        $id = $p['entry']['id'];
        self::assertSame('ok', $p['entry']['extraction']['fields']['meter_number']['status'], 'heute gefunden');
        self::assertArrayNotHasKey('pages', $this->store->read('inbox.json', [])[0]['extraction']['text'], 'die Textebene wird nicht gespeichert');
        // so hätte eine frühere Auslesung gespeichert: Zitat mit „…“ nicht gefunden
        $all = $this->store->read('inbox.json', []);
        $all[0]['extraction']['fields']['meter_number']['status'] = 'check';
        $all[0]['extraction']['fields']['meter_number']['reasons'] = ['quote_not_found'];
        $this->store->write('inbox.json', $all);
        $s->update($id, []);
        self::assertSame(['quote_not_found'], $this->store->read('inbox.json', [])[0]['extraction']['fields']['meter_number']['reasons'], 'die Marken nach dem Bestand ändern sie nicht');
        $s->extract($id, InboxFixture::invoicePages(), false, true);
        self::assertSame('ok', $this->store->read('inbox.json', [])[0]['extraction']['fields']['meter_number']['status'], 'erst neu auslesen');
    }
}
