<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Gist;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\DraftFixture;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 — Rechnungskorrektur im laufenden Posteingang (Rest aus der Eingangszeile
 * „Preisbestandteile/Rechnungskorrekturen“). Im Entwurf ersetzt eine Korrektur die Erstfassung seit
 * v3.7.0; im laufenden Posteingang blieb sie ein Widerspruch (nicht angehakt), und eine schon gebuchte
 * Rechnung behielt ihr altes Ergebnis als Sonderzahlung.
 *
 * Erfundener Haushalt, Strom, Vertrag „Stadtwerke Musterstadt“ seit 01.01.2025 (30 ct/kWh, 10 € im
 * Monat, Abschlag 70 €). Jahresrechnung 2025, Rechnung R-1000 vom 10.02.2026: 2.500 kWh × 0,30 € +
 * 12 × 10 € = 870,00 €, Abschläge 12 × 70 € = 840,00 €, Nachzahlung 30,00 € (übernommen und gebucht).
 * Korrektur R-1001 vom 20.03.2026 „ersetzt Rechnung R-1000“: 2.300 kWh → 690 € + 120 € = 810,00 €,
 * Guthaben 30,00 €.
 */
#[CoversClass(Proposals::class)]
#[CoversClass(Applier::class)]
#[CoversClass(BillService::class)]
#[CoversClass(Gist::class)]
final class InboxBillCorrectionTest extends ServiceTestCase
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
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings);
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, InboxFixture::provider()),
            $proposals, new Applier($this->meters, $this->contracts, $this->readings, $this->bills, $reminders, $this->settings, $this->i18n));
        $this->inbox->attachGist(new Gist($this->meters, $this->contracts, $this->consumption, $this->bills, $this->readings));
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

    /** Jahresrechnung 2025 mit Ergebnis (`due`/`credit`) und weiteren Feldern (erfunden). */
    private function invoice(string $tag, string $kwh, string $total, string $kind, string $result, array $more = []): string
    {
        return DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), $tag, 'invoice', [
            'utility' => 'strom', 'supplier' => 'Stadtwerke Musterstadt', 'kind' => 'annual',
            'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'consumption' => $kwh,
            'meter_number' => 'S-4711', 'total_amount' => $total, 'advances_total' => '840,00', 'result_kind' => $kind, 'result_amount' => $result,
        ] + $more);
    }

    /** Die Erstfassung übernehmen (angehakt: Rechnung und Buchung). */
    private function original(): string
    {
        $id = $this->invoice('erst', '2500', '870,00', 'due', '30,00', ['document_date' => '10.02.2026', 'invoice_number' => 'R-1000']);
        $pv = $this->inbox->preview($id);
        $res = $this->inbox->apply($id, array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        return $id;
    }

    /** @return array{0: array<string,mixed>, 1: list<array<string,mixed>>} die Rechnung und die Sonderzahlungen des Vertrags */
    private function stock(): array
    {
        $bills = $this->bills->list('strom', $this->meterId);
        self::assertCount(1, $bills, 'eine Rechnung, keine zweite');
        return [$bills[0], array_values((array)($this->contracts->list('strom', $this->meterId)[0]['special_payments'] ?? []))];
    }

    private static function kind(array $pv, string $kind): array
    {
        $p = array_values(array_filter($pv['proposals'], fn($x) => $x['kind'] === $kind));
        self::assertCount(1, $p, $kind);
        return $p[0];
    }

    public function testACorrectionReplacesABookedBillAndItsBooking(): void
    {
        $this->original();
        [$bill, $sps] = $this->stock();
        self::assertSame([30.0, 1, 'nachzahlung_ohne', 30.0], [(float)$bill['invoice']['result_eur'], count($sps), $sps[0]['kind'], (float)$sps[0]['amount_eur']]);
        $oldSp = (string)$bill['special_payment_id'];

        $id = $this->invoice('zweite', '2300', '810,00', 'credit', '30,00',
            ['document_date' => '20.03.2026', 'invoice_number' => 'R-1001', 'corrects_invoice' => 'R-1000', 'document_title' => 'Rechnung']);
        $pv = $this->inbox->preview($id);
        $b = self::kind($pv, 'bill');
        // Bezug, andere Nummer, später ausgestellt; der Titel sagt nichts („Rechnung“)
        self::assertSame(['conflict', true, true, ['reference', 'number', 'later']], [$b['status'], $b['replaces'] ?? null, $b['checked'], $b['correction'] ?? null]);
        self::assertSame(['inbox.proposal.billCorrection', -30.0, 30.0], [$b['label_key'], (float)$b['params']['result'], (float)$b['params']['before']]);
        $book = self::kind($pv, 'bill_book');
        self::assertSame(['conflict', true, 'inbox.proposal.billRebook', true], [$book['status'], $book['checked'], $book['label_key'], $book['data']['rebook'] ?? null]);
        self::assertSame(['bill_id' => (string)$bill['id'], 'special_payment_id' => $oldSp, 'before' => 30.0], $book['detail']);
        // klare Anzeige gleich in der Kernaussage (Skeptiker N7: bis dahin erst unter „Mehr dazu“): ersetzt die
        // erfasste und gebuchte Rechnung 2025, Ergebnis Guthaben 30 € statt Nachzahlung 30 €
        self::assertSame(['inbox.gist.invoice.correctionBooked', '2025-01-01', '2025-12-31', 30.0, -30.0],
            [$pv['gist']['key'], $pv['gist']['params']['from'], $pv['gist']['params']['to'], (float)$pv['gist']['params']['balance'], (float)$pv['gist']['params']['result']]);
        self::assertSame(1, preg_match('/^Rechnungskorrektur .+: Das Ergebnis wird .+\. Sie ersetzt die erfasste und gebuchte Rechnung/u',
            $this->i18n->t($pv['gist']['key'], ['from' => 'x', 'to' => 'y', 'balance' => 'b', 'result' => 'r'])));

        $res = $this->inbox->apply($id, array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        [$bill, $sps] = $this->stock();
        // Werte der Korrektur, beide Dokumente als Beleg; die Nachzahlung ist weg, das Guthaben gebucht
        self::assertSame([2300.0, 810.0, -30.0, 2], [(float)$bill['invoice']['energy_kwh'], (float)$bill['invoice']['amount_eur'],
            (float)$bill['invoice']['result_eur'], count($bill['attachment_ids'])]);
        self::assertCount(1, $sps);
        self::assertSame(['rueckzahlung_ohne', 30.0], [$sps[0]['kind'], (float)$sps[0]['amount_eur']]);
        self::assertNotSame($oldSp, (string)$bill['special_payment_id']);
        self::assertSame((string)$sps[0]['id'], (string)$bill['special_payment_id']);
        // noch einmal angesehen: alles schon vorhanden
        $again = $this->inbox->preview($id);
        self::assertSame(['exists', 'exists'], [self::kind($again, 'bill')['status'], self::kind($again, 'bill_book')['status']]);
    }

    /** Skeptiker N7 — eine nicht gebuchte erfasste Rechnung: dieselbe Kernaussage ohne „gebucht“, dann gilt wieder das Urteil. */
    public function testTheGistOfACorrectionOfAnUnbookedBill(): void
    {
        $id = $this->invoice('erst', '2500', '870,00', 'due', '30,00', ['document_date' => '10.02.2026', 'invoice_number' => 'R-1000']);
        $pv = $this->inbox->preview($id);
        $res = $this->inbox->apply($id, array_column(array_filter($pv['proposals'], fn($p) => $p['checked'] && $p['kind'] !== 'bill_book'), 'id'));
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        self::assertNull($this->stock()[0]['special_payment_id'] ?? null, 'Vorbedingung: nicht gebucht');
        $kid = $this->invoice('zweite', '2300', '810,00', 'credit', '30,00', ['document_date' => '20.03.2026', 'corrects_invoice' => 'R-1000']);
        $pv = $this->inbox->preview($kid);
        self::assertSame(['inbox.gist.invoice.correction', 30.0, -30.0],
            [$pv['gist']['key'], (float)$pv['gist']['params']['balance'], (float)$pv['gist']['params']['result']]);
        // übernommen: die Rechnung ist geprüft, die Kernaussage ist das Urteil von „Rechnung prüfen“
        $this->inbox->apply($kid, array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        self::assertMatchesRegularExpression('/^inbox\.gist\.invoice\.(ok|offMore|offLess|offQuantity|noCompare)$/', $this->inbox->preview($kid)['gist']['key']);
        // die Anleitung zitiert den Satz so, wie der Katalog ihn hat (Platzhalter als „…“)
        $root = dirname(__DIR__, 3);
        foreach (['de' => 'docs/anleitungen/posteingang.md', 'en' => 'docs/en/anleitungen/posteingang.md'] as $lang => $rel) {
            $cat = json_decode((string)file_get_contents("$root/public/locales/$lang.json"), true)['inbox']['gist']['invoice']['correction'];
            $quoted = (string)preg_replace('/\{\w+\}/', '…', explode('. ', $cat)[0]);
            self::assertStringContainsString($quoted, (string)preg_replace('/\s+/u', ' ', (string)file_get_contents("$root/$rel")), "$rel: $quoted");
        }
    }

    public function testALaterIssueDateAloneIsEnough(): void
    {
        $this->original();
        // keine Nummer, kein Bezug, kein Titel: nur das spätere Datum (nach dem 10.02.2026 der Erstfassung)
        $pv = $this->inbox->preview($this->invoice('spaeter', '2300', '810,00', 'credit', '30,00', ['document_date' => '20.03.2026']));
        $b = self::kind($pv, 'bill');
        self::assertSame(['later'], $b['correction'] ?? null);
        self::assertTrue($b['checked']);
    }

    public function testATitleMarkIsEnoughButACorrectionFactorIsNot(): void
    {
        $this->original();
        $pv = $this->inbox->preview($this->invoice('titel', '2300', '810,00', 'credit', '30,00', ['document_title' => 'Rechnungskorrektur']));
        self::assertSame(['marked'], self::kind($pv, 'bill')['correction'] ?? null);
        $this->store->write('inbox.json', array_values(array_filter($this->store->read('inbox.json', []), fn($x) => ($x['name'] ?? '') === 'erst.pdf')));
        $pv = $this->inbox->preview($this->invoice('faktor', '2300', '810,00', 'credit', '30,00', ['document_title' => 'Jahresabrechnung mit Korrekturfaktor']));
        $b = self::kind($pv, 'bill');
        self::assertSame(['conflict', false, 'inbox.proposal.bill'], [$b['status'], $b['checked'], $b['label_key']], 'wie bisher: Widerspruch, nicht angehakt');
        self::assertArrayNotHasKey('replaces', $b);
    }

    public function testAnOlderVersionOrOneWithoutAnySignStaysAConflict(): void
    {
        $this->original();
        // ausgestellt vor der erfassten Fassung (05.02. gegen 10.02.), auch mit anderer Nummer: die frühere Fassung
        $pv = $this->inbox->preview($this->invoice('frueher', '2400', '840,00', 'due', '0,00', ['document_date' => '05.02.2026', 'invoice_number' => 'R-0999']));
        $b = self::kind($pv, 'bill');
        self::assertSame(['conflict', false], [$b['status'], $b['checked']]);
        self::assertArrayNotHasKey('correction', $b);
        // ohne Datum, Nummer, Bezug und Titel: wie bisher ein Widerspruch zum Prüfen
        $this->store->write('inbox.json', array_values(array_filter($this->store->read('inbox.json', []), fn($x) => ($x['name'] ?? '') === 'erst.pdf')));
        $pv = $this->inbox->preview($this->invoice('ohne', '2300', '810,00', 'credit', '30,00'));
        self::assertSame(['conflict', false], [self::kind($pv, 'bill')['status'], self::kind($pv, 'bill')['checked']]);
        self::assertSame('exists', self::kind($pv, 'bill_book')['status'], 'die alte Buchung bleibt, bis du die Korrektur übernimmst');
    }

    public function testACorrectionToZeroRemovesTheBooking(): void
    {
        $this->original();
        // Korrektur auf genau die Abschläge: 2.400 kWh → 720 € + 120 € = 840,00 €, Ergebnis 0 €
        $id = $this->invoice('null', '2400', '840,00', 'due', '0,00', ['document_date' => '20.03.2026', 'corrects_invoice' => 'R-1000']);
        $pv = $this->inbox->preview($id);
        $book = self::kind($pv, 'bill_book');
        self::assertSame(['conflict', 0.0, 30.0], [$book['status'], (float)$book['params']['result'], (float)$book['params']['before']]);
        $res = $this->inbox->apply($id, array_column(array_filter($pv['proposals'], fn($p) => $p['checked']), 'id'));
        self::assertSame([], $res['failed'], json_encode($res['failed']));
        [$bill, $sps] = $this->stock();
        self::assertSame([0.0, null, []], [(float)$bill['invoice']['result_eur'], $bill['special_payment_id'] ?? null, $sps]);
    }

    public function testTheSchemaAsksForNumberAndReference(): void
    {
        $f = InboxSchema::fields('invoice');
        self::assertSame([true, true], [!empty($f['invoice_number']['helper']), !empty($f['corrects_invoice']['helper'])]);
        self::assertGreaterThanOrEqual(6, InboxSchema::VERSION, 'Schema mit Rechnungsnummer und Bezug (seit 6)');
    }
}
