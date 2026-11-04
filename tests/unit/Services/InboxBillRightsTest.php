<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\DraftFixture;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.11.0 (F1035, Paket rechnung) — was der Posteingang für „Deine Rechte als Kunde“ an die
 * Rechnung gibt: das Rechnungsdatum (Befund B5: bis v3.10 blieb `issued_on` leer, A1 und die
 * Buchung nahmen den Tag nach dem Zeitraum), Gutschriften und das Messentgelt als Posten (A4, A6),
 * geschätzte Ränder (A5), der Gebührenbescheid (A7). Eine Korrektur behält das Datum der ersten
 * Fassung (A1) und ersetzt Gutschriften und Schätzung.
 *
 * Erfundener Haushalt wie InboxBillCorrectionTest: Strom, Vertrag „Stadtwerke Musterstadt“ seit
 * 01.01.2025 (30 ct/kWh, 10 € im Monat, Abschlag 70 €), Jahresrechnung 2025.
 */
#[CoversClass(Proposals::class)]
#[CoversClass(Applier::class)]
final class InboxBillRightsTest extends ServiceTestCase
{
    private InboxService $inbox;
    private BillService $bills;
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, InboxFixture::provider()),
            new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings),
            new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_s1', 'serial' => 'S-4711', 'installed_on' => '2020-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('strom', $this->meterId, [
            ['date' => '2025-01-01', 'counter' => 1000.0, 'device_id' => 'd_s1'],
            ['date' => '2025-12-31', 'counter' => 3500.0, 'device_id' => 'd_s1'],
        ]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 70.0]]]);
    }

    /** Jahresrechnung 2025: 2.500 kWh, 870 €, Abschläge 840 €, Ergebnis wie angegeben (erfunden). */
    private function invoice(string $tag, string $kind, string $result, array $more = []): string
    {
        return DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), $tag, 'invoice', [
            'utility' => 'strom', 'supplier' => 'Stadtwerke Musterstadt', 'kind' => 'annual',
            'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'consumption' => '2500',
            'meter_number' => 'S-4711', 'total_amount' => '870,00', 'advances_total' => '840,00', 'result_kind' => $kind, 'result_amount' => $result,
        ] + $more);
    }

    private function applyChecked(string $id): void
    {
        $pv = $this->inbox->preview($id);
        $res = $this->inbox->apply($id, array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        self::assertSame([], $res['failed'], json_encode($res['failed']));
    }

    private function onlyBill(): array
    {
        $bills = $this->bills->list('strom', $this->meterId);
        self::assertCount(1, $bills);
        return $bills[0];
    }

    public function testTheBillTakesItsDateCreditsAndMeteringChargeAndBooksOnItsDate(): void
    {
        $id = $this->invoice('erst', 'due', '30,00', ['document_date' => '10.02.2026',
            'credits' => [['label' => 'Neukundenbonus', 'amount' => '100,00', 'kind' => 'bonus']],
            'metering_fee' => '24,00', 'metering_fee_basis' => 'gross', 'metering_fee_vat_pct' => '19']);
        $bill = array_values(array_filter($this->inbox->preview($id)['proposals'], fn($p) => $p['kind'] === 'bill'))[0];
        self::assertSame('2026-02-10', $bill['data']['issued_on'] ?? null, 'B5: Rechnungsdatum aus dem Dokument');
        $this->applyChecked($id);
        $b = $this->onlyBill();
        self::assertSame('2026-02-10', $b['issued_on']);
        // (assertEquals: JSON speichert 100.0 als 100)
        self::assertEquals([['label' => 'Neukundenbonus', 'amount_eur' => -100.0, 'kind' => 'credit'],
            ['label' => '', 'amount_eur' => 24.0, 'kind' => 'metering', 'basis' => 'gross', 'vat_pct' => 19.0]], $b['items']);
        self::assertArrayNotHasKey('received_on', $b, 'den Zugang kennt das Dokument nicht');
        // gebucht am Rechnungsdatum (bis v3.10: 01.01.2026, der Tag nach dem Zeitraum)
        $sp = (array)($this->contracts->list('strom', $this->meterId)[0]['special_payments'] ?? []);
        self::assertSame(['2026-02-10', 30.0], [$sp[0]['date'] ?? null, (float)($sp[0]['amount_eur'] ?? 0)]);
    }

    public function testAMarkedCreditOrChargeStaysOut(): void
    {
        $id = $this->invoice('unsicher', 'due', '30,00', ['document_date' => '10.02.2026',
            'credits' => [['label' => 'Bonus', 'amount' => '100,00', 'kind' => 'bonus']], 'metering_fee' => '24,00'], );
        // dasselbe Dokument, beide Angaben mit einer Belegstelle, die nicht im Text steht (markiert)
        DraftFixture::patch($this->store, $id, 'invoice', ['utility' => 'strom', 'supplier' => 'Stadtwerke Musterstadt', 'kind' => 'annual',
            'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'consumption' => '2500', 'meter_number' => 'S-4711',
            'total_amount' => '870,00', 'advances_total' => '840,00', 'result_kind' => 'due', 'result_amount' => '30,00', 'document_date' => '10.02.2026',
            'credits' => [['label' => 'Bonus', 'amount' => '100,00', 'kind' => 'bonus']], 'metering_fee' => '24,00'], ['credits', 'metering_fee']);
        $bill = array_values(array_filter($this->inbox->preview($id)['proposals'], fn($p) => $p['kind'] === 'bill'))[0];
        self::assertArrayNotHasKey('items', $bill['data'], json_encode($bill['data']));
    }

    public function testACorrectionKeepsTheFirstDateAndReplacesCreditsAndTheEstimate(): void
    {
        $this->applyChecked($this->invoice('erst', 'due', '30,00', ['document_date' => '10.02.2026', 'invoice_number' => 'R-1000']));
        $b = $this->onlyBill();
        self::assertSame('R-1000', $b['invoice_number'] ?? null, 'v3.11.0 (integration): Rechnungsnummer aus dem Dokument');
        // erfasst: geschätzter Endstand, eine Umlage und eine (zu kleine) Gutschrift
        $this->bills->update('strom', (string)$b['id'], ['reading_estimated' => ['start' => false, 'end' => true], 'items' => [
            ['label' => 'Umlage', 'amount_eur' => 5.0, 'kind' => 'levy'], ['label' => 'Bonus', 'amount_eur' => -20.0, 'kind' => 'credit']]]);
        $id = $this->invoice('zweite', 'credit', '30,00', ['document_date' => '20.03.2026', 'invoice_number' => 'R-1001', 'corrects_invoice' => 'R-1000',
            'credits' => [['label' => 'Neukundenbonus', 'amount' => '100,00', 'kind' => 'bonus']]]);
        $this->applyChecked($id);
        $b = $this->onlyBill();
        self::assertSame('2026-02-10', $b['issued_on'], 'die Frist des § 40c EnWG betrifft die erste Fassung');
        self::assertSame('R-1001', $b['invoice_number'], 'Briefe beziehen sich auf die geltende Fassung');
        self::assertNull($b['reading_estimated'], 'die Korrektur nennt keine Schätzung mehr');
        self::assertEquals([['label' => 'Umlage', 'amount_eur' => 5.0, 'kind' => 'levy'], ['label' => 'Neukundenbonus', 'amount_eur' => -100.0, 'kind' => 'credit']],
            $b['items'], 'Gutschriften ersetzt, andere Posten bleiben');
    }

    /**
     * v3.11.0 (integration) — die Rechnung nennt, ob Grundversorgung vorliegt (§ 40 Abs. 2 Satz 1 Nr. 13 EnWG):
     * steht am Vertrag noch keine Versorgungsart, schlägt der Posteingang sie vor (nicht still); eine
     * vorhandene bleibt, eine unsicher gelesene Angabe ergibt keinen Vorschlag.
     */
    public function testTheBillProposesTheTypeOfSupplyWhenTheContractHasNone(): void
    {
        $kinds = fn(string $id) => array_values(array_filter($this->inbox->preview($id)['proposals'], fn($x) => $x['kind'] === 'contract_supply_kind'));
        $id = $this->invoice('gv', 'due', '30,00', ['basic_supply' => true]);
        $p = $kinds($id);
        self::assertCount(1, $p);
        self::assertSame(['inbox.proposal.supplyKindBasic', 'basic', 'new', true], [$p[0]['label_key'], $p[0]['data']['supply_kind'], $p[0]['status'], $p[0]['checked']]);
        $this->applyChecked($id);
        $c = $this->contracts->list('strom', $this->meterId)[0];
        self::assertSame('basic', $c['supply_kind'] ?? null);
        self::assertSame([], $kinds($id), 'steht jetzt am Vertrag');
        // eine vorhandene Angabe bleibt, auch wenn ein anderes Dokument etwas anderes nennt
        self::assertSame([], $kinds($this->invoice('sv', 'due', '30,00', ['basic_supply' => false])));
        // ohne Angabe am Vertrag: „Sondervertrag“; ohne Angabe im Dokument und außerhalb Deutschlands: kein Vorschlag
        $this->contracts->update('strom', (string)$c['id'], ['supply_kind' => '']);
        $sv = $this->invoice('sv2', 'due', '30,00', ['basic_supply' => false]);
        self::assertSame('inbox.proposal.supplyKindSpecial', $kinds($sv)[0]['label_key'] ?? null);
        self::assertSame([], $kinds($this->invoice('ohne', 'due', '30,00')));
        // inzwischen von Hand eingetragen: der Vorschlag ist überholt, Übernehmen ändert nichts
        $race = $kinds($sv)[0]['id'];
        $this->contracts->update('strom', (string)$c['id'], ['supply_kind' => 'basic']);
        try { $this->inbox->apply($sv, [$race]); self::fail('überholter Vorschlag übernommen'); } catch (\Energietracker\Support\LocalizedException) {}
        self::assertSame('basic', $this->contracts->get('strom', (string)$c['id'])['supply_kind'] ?? null);
        $this->contracts->update('strom', (string)$c['id'], ['supply_kind' => '']);
        $this->settings->set(['country' => 'AT']);
        self::assertSame([], $kinds($sv));
        // ein Vertrag, der aus der Rechnung entsteht, trägt die Angabe selbst
        $this->settings->set(['country' => 'DE']);
        $this->store->write('strom/contracts.json', []);
        $new = array_values(array_filter($this->inbox->preview($this->invoice('neu', 'due', '30,00', ['basic_supply' => true]))['proposals'],
            fn($x) => $x['kind'] === 'contract_create'));
        self::assertSame('basic', $new[0]['data']['supply_kind'] ?? null);
    }

    /** Die Felder aus der Auslesung, ohne Dokument (Proposals::rightsFields). */
    public function testRightsFieldsFromAnExtraction(): void
    {
        $entry = fn(array $fields, array $edits = []) => ['extraction' => ['fields' => $fields], 'edits' => $edits];
        $v = fn($x, string $status = 'ok') => ['value' => $x, 'status' => $status];
        // Wasser: Gebührenbescheid mit Datum, Widerspruch laut Belehrung, Aktenzeichen
        $f = ['document_date' => $v('2026-01-27'), 'fee_notice' => $v(true), 'remedy' => $v('objection'), 'file_number' => $v(' GB-2026/17 ')];
        self::assertSame(['issued_on' => '2026-01-27', 'notice' => ['public_law' => true, 'dated_on' => '2026-01-27', 'remedy' => 'objection', 'file_number' => 'GB-2026/17']],
            Proposals::rightsFields('wasser', $entry($f), $f));
        // ein unsicherer Rechtsbehelf bleibt leer (der Brief „Widerspruch“ braucht ihn); bei Strom kein Bescheid
        $g = ['remedy' => $v('objection', 'check')] + $f;
        self::assertNull(Proposals::rightsFields('wasser', $entry($g), $g)['notice']['remedy']);
        self::assertArrayNotHasKey('notice', Proposals::rightsFields('strom', $entry($f), $f));
        // ohne Bescheiddatum kein Bescheid (das Datum ist Pflicht)
        $h = ['fee_notice' => $v(true)];
        self::assertSame([], Proposals::rightsFields('wasser', $entry($h), $h));
        // geschätzte Ränder: Einzelfelder oder je Zähler (`estimated_ends`); nichts geschätzt → kein Feld
        $r = ['reading_new_estimated' => $v(true)];
        self::assertSame(['reading_estimated' => ['start' => false, 'end' => true]], Proposals::rightsFields('strom', $entry($r), $r));
        $m = ['meter_readings' => ['items' => [['value' => ['meter_number' => 'A', 'estimated' => false], 'estimated_ends' => [true, false]]]]];
        self::assertSame(['reading_estimated' => ['start' => true, 'end' => false]], Proposals::rightsFields('strom', $entry($m), $m));
        $n = ['reading_new_estimated' => $v(false), 'reading_old_estimated' => $v(false)];
        self::assertSame([], Proposals::rightsFields('strom', $entry($n), $n));
        // Messentgelt netto mit Satz; ein Satz außerhalb 0 bis 30 % fällt weg; Korrektur des Nutzers gilt
        $p = ['metering_fee' => $v(20.17), 'metering_fee_basis' => $v('net'), 'metering_fee_vat_pct' => $v(45)];
        self::assertSame([['label' => '', 'amount_eur' => 20.17, 'kind' => 'metering', 'basis' => 'net']], Proposals::rightsFields('strom', $entry($p), $p)['items']);
        self::assertSame(19.0, Proposals::rightsFields('strom', $entry($p, ['metering_fee_vat_pct' => 19]), $p)['items'][0]['vat_pct']);
        // v3.11.0 (integration) — Rechnungsnummer (Bezug der Briefe), nur sicher gelesen
        $i = ['invoice_number' => $v(' R-2026-17 ')];
        self::assertSame(['invoice_number' => 'R-2026-17'], Proposals::rightsFields('strom', $entry($i), $i));
        $j = ['invoice_number' => $v('R-2026-17', 'check')];
        self::assertSame([], Proposals::rightsFields('strom', $entry($j), $j));
    }
}
