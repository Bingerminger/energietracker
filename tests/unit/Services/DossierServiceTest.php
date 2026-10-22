<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\NotFoundException;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\DossierService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (Paket betrieb) — Akte je Zähler und Vertrag: übernommene Post,
 * Rechnungen mit PDF, Belege am Vertrag, Fotos der Ablesungen; ein PDF steht
 * nur einmal, neueste zuerst.
 */
#[CoversClass(DossierService::class)]
final class DossierServiceTest extends ServiceTestCase
{
    private AttachmentService $att;
    private BillService $bills;
    private InboxService $inbox;
    private DossierService $dossier;
    private string $contractId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $provider = InboxFixture::provider('local', ['Jahresabrechnung' => 'invoice', 'Mahnung' => 'dunning'], ['invoice' => InboxFixture::invoiceAnswer()]);
        $this->inbox = new InboxService($this->store, $this->i18n, $this->att, new ProviderRegistry($this->settings, $provider),
            new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings),
            new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $this->dossier = new DossierService($this->inbox, $this->att, $this->bills, $this->contracts, $this->meters, $this->readings, $this->i18n);
        $all = $this->store->read('gas/meters.json', []);
        $all[0]['devices'][0]['serial'] = InboxFixture::METER;
        $all[0]['devices'][0]['installed_on'] = '2020-01-01';
        $this->store->write('gas/meters.json', $all);
        $this->contractId = (string)$this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Stadtwerke Musterstadt',
            'start' => '2024-01-01', 'contract_number' => 'VK-778899',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.0]], 'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 140.0]]])['id'];
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-12-31', 'counter' => 12345]);
    }

    public function testOneFileForAllDocumentsOfAMeterNewestFirst(): void
    {
        // Jahresabrechnung aus dem Posteingang übernommen: Eintrag mit Rechnung, das PDF nur einmal
        $e = $this->inbox->upload(InboxFixture::pdf('inv'), 'Abrechnung 2025.pdf');
        $this->inbox->classify($e['id'], InboxFixture::invoicePages());
        $this->inbox->extract($e['id'], InboxFixture::invoicePages());
        $pv = $this->inbox->preview($e['id']);
        $res = $this->inbox->apply($e['id'], array_column(array_filter($pv['proposals'], fn($p) => $p['kind'] === 'bill'), 'id'));
        $billId = $res['applied'][0]['ref']['id'];
        // eine ältere Rechnung von Hand, ohne PDF
        $this->bills->create('gas', ['meter_id' => 'm_gas_default', 'kind' => 'annual', 'period_from' => '2024-01-01', 'period_to' => '2024-12-31',
            'invoice' => ['amount_eur' => 1500.0, 'result_eur' => -20.0]]);
        // Beleg am Vertrag, Foto einer Ablesung
        $receipt = $this->att->store(InboxFixture::pdf('rcpt'), 'other', 'Vertrag.pdf');
        $this->contracts->update('gas', $this->contractId, ['attachment_ids' => [$receipt['id']]]);
        $photo = $this->att->store("\xFF\xD8\xFF\xE0 foto", 'reading_photo', 'zaehler.jpg');
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2025-06-30', 'counter' => 12900, 'attachment_id' => $photo['id']]);
        // noch nicht erledigt, aber schon zugeordnet: gehört nicht in die Akte (sondern in den Posteingang)
        $open = $this->inbox->upload(InboxFixture::pdf('open'), 'offen.pdf');
        $this->inbox->update($open['id'], ['assignment' => ['utility' => 'gas', 'meter_id' => 'm_gas_default', 'contract_id' => $this->contractId]]);

        $items = $this->dossier->forMeter('gas', 'm_gas_default');
        self::assertSame(['inbox', 'photo', 'bill'], array_column(array_filter($items, fn($i) => $i['kind'] !== 'receipt'), 'kind'));
        $inbox = array_values(array_filter($items, fn($i) => $i['kind'] === 'inbox'))[0];
        self::assertSame(['2025-12-31', 'invoice', 'Abrechnung 2025.pdf', $billId, $e['attachment_id']],
            [$inbox['date'], $inbox['type'], $inbox['title'], $inbox['bill_id'], $inbox['attachment_id']]);
        self::assertCount(1, array_filter($items, fn($i) => ($i['bill_id'] ?? null) === $billId), 'Rechnung aus dem Posteingang nicht doppelt');
        $bill = array_values(array_filter($items, fn($i) => $i['kind'] === 'bill'))[0];
        self::assertSame(['2024-12-31', -20.0, null], [$bill['date'], $bill['result_eur'], $bill['attachment_id']]);
        $r = array_values(array_filter($items, fn($i) => $i['kind'] === 'receipt'))[0];
        self::assertSame([$receipt['id'], 'Vertrag.pdf', $this->contractId], [$r['attachment_id'], $r['title'], $r['contract_id']]);
        $p = array_values(array_filter($items, fn($i) => $i['kind'] === 'photo'))[0];
        self::assertSame(['2025-06-30', 12900.0, 'zaehler.jpg'], [$p['date'], $p['counter'], $p['title']]);
        $dates = array_column($items, 'date');
        $sorted = $dates;
        rsort($sorted);
        self::assertSame($sorted, $dates, 'neueste zuerst');
    }

    public function testTheContractFileHoldsItsDocumentsOnly(): void
    {
        $e = $this->inbox->upload(InboxFixture::pdf('dun'), 'mahnung.pdf');
        $this->inbox->update($e['id'], ['type' => 'dunning', 'assignment' => ['utility' => 'gas', 'meter_id' => 'm_gas_default', 'contract_id' => $this->contractId]]);
        $other = $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Musterenergie', 'start' => '2030-01-01',
            'working_prices' => [['from' => '2030-01-01', 'ct_per_kwh' => 10.0]]]);
        $this->bills->create('gas', ['meter_id' => 'm_gas_default', 'kind' => 'annual', 'period_from' => '2024-01-01', 'period_to' => '2024-12-31',
            'invoice' => ['amount_eur' => 1500.0]]);
        $mine = $this->dossier->forContract('gas', $this->contractId);
        self::assertSame(['inbox', 'bill'], array_column($mine, 'kind'));
        self::assertSame('dunning', $mine[0]['type']);
        self::assertSame([], $this->dossier->forContract('gas', (string)$other['id']), 'Rechnung und Mahnung gehören zum anderen Vertrag');
        $this->expectException(NotFoundException::class);
        $this->dossier->forContract('gas', 'c_gibtsnicht');
    }
}
