<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\ItemEdits;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket F, Bekannte Grenze v3.12.0) — Listenfelder im Posteingang je Eintrag bestätigen, korrigieren
 * oder verwerfen (Inbox\ItemEdits). Geprüft am Ergebnis: der Vorschlag aus dem Eintrag (Marke, Vorbelegung,
 * Wert), die Zahl der zu prüfenden Felder, die Prüfliste, die Herkunft in der Vorschau, das Zurücksetzen und
 * ein neues Auslesen; die gelesenen Werte bleiben gespeichert. Erfundene Rechnung aus InboxFixture.
 */
#[CoversClass(ItemEdits::class)]
#[CoversClass(InboxService::class)]
final class InboxListItemsTest extends ServiceTestCase
{
    private InboxService $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $local = InboxFixture::provider('local', ['Jahresabrechnung' => 'invoice'], ['invoice' => InboxFixture::invoiceAnswer()]);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings);
        $proposals->setToday('2026-03-01');
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, $local), $proposals,
            new Applier($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n));
        $this->inbox->attachActivity(new ActivityService($this->store, $this->settings, $this->i18n));
        // Gas-Haushalt wie InboxServiceTest: Nummer am Gerät, Vertrag mit einem anderen Arbeitspreis als die Rechnung
        $all = $this->store->read('gas/meters.json', []);
        $all[0]['devices'][0]['serial'] = InboxFixture::METER;
        $all[0]['devices'][0]['installed_on'] = '2020-01-01';
        $this->store->write('gas/meters.json', $all);
        $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Gas Basis',
            'start' => '2024-01-01', 'contract_number' => 'VK-778899',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 11.0]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 140.0]]]);
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-12-31', 'counter' => 12345]);
    }

    /** @return array<string,mixed> Vorschau der ausgelesenen Rechnung */
    private function invoice(): array
    {
        $e = $this->inbox->upload(InboxFixture::pdf('liste'), 'liste.pdf');
        $this->inbox->classify($e['id'], InboxFixture::invoicePages());
        $this->inbox->extract($e['id'], InboxFixture::invoicePages());
        return $this->inbox->preview($e['id']);
    }

    /** @return list<array<string,mixed>> */
    private static function byKind(array $pv, string $kind): array
    {
        return array_values(array_filter($pv['proposals'], fn($p) => $p['kind'] === $kind));
    }

    public function testConfirmingAPriceLineRemovesItsMarkAndPreChecksItsProposal(): void
    {
        $pv = $this->invoice();
        $id = (string)$pv['entry']['id'];
        $wp = $pv['entry']['extraction']['fields']['working_prices'];
        self::assertSame('check', $wp['items'][0]['status'], 'Preis aus der Rechnung ≠ Vertrag: zu prüfen');
        $price = self::byKind($pv, 'contract_price')[0];
        self::assertSame([true, false], [$price['check'], $price['checked']]);
        $flags = (int)$pv['entry']['extraction']['flags'];
        $review = count(array_filter($this->inbox->review(), fn($r) => $r['field'] === 'working_prices'));
        self::assertSame(1, $review);

        $after = $this->inbox->update($id, ['item_edits' => ['working_prices' => ['0' => ['action' => 'confirm']]]]);
        self::assertSame('confirm', $after['item_edits']['working_prices']['0']['action']);
        $pv2 = $this->inbox->preview($id);
        $item = $pv2['entry']['extraction']['fields']['working_prices']['items'][0];
        self::assertSame(['ok', 'read', true, 'confirm'], [$item['status'], $item['origin'], $item['confirmed'], $item['decision']]);
        $price2 = self::byKind($pv2, 'contract_price')[0];
        self::assertSame([false, true], [$price2['check'], $price2['checked']], 'bestätigt: ohne Marke, vorbelegt');
        self::assertEqualsWithDelta(11.9, (float)$price2['data']['value'], 0.0001, 'der gelesene Wert');
        self::assertSame($flags - 1, (int)$pv2['entry']['extraction']['flags']);
        $listed = array_values(array_filter($this->inbox->list(), fn($s) => $s['id'] === $id))[0];
        self::assertSame($flags - 1, (int)$listed['flags'], 'die Liste zählt wie die Vorschau');
        self::assertSame([], array_values(array_filter($this->inbox->review(), fn($r) => $r['field'] === 'working_prices')), 'nicht mehr in der Prüfliste');
        // gespeichert bleibt die Auslesung wie gelesen
        $raw = array_values(array_filter($this->inbox->rawEntries(), fn($e) => $e['id'] === $id))[0];
        self::assertSame('check', $raw['extraction']['fields']['working_prices']['items'][0]['status']);
    }

    public function testACorrectedLineCarriesTheNewValueAndItsOrigin(): void
    {
        $pv = $this->invoice();
        $id = (string)$pv['entry']['id'];
        $this->inbox->update($id, ['item_edits' => ['working_prices' => ['0' => ['action' => 'correct', 'value' => ['ct' => '12,10']]]]]);
        $pv2 = $this->inbox->preview($id);
        $item = $pv2['entry']['extraction']['fields']['working_prices']['items'][0];
        self::assertSame(['ok', 'corrected', 'correct'], [$item['status'], $item['origin'], $item['decision']]);
        self::assertEqualsWithDelta(12.1, (float)$item['value']['ct'], 0.0001);
        self::assertEqualsWithDelta(11.9, (float)$item['read_value']['ct'], 0.0001, 'gelesen bleibt sichtbar');
        self::assertSame('2025-01-01', $item['value']['from'], 'nicht korrigierte Unterfelder bleiben');
        // eine zweite Korrektur (nur das Datum) behält die erste (die Oberfläche schickt nur Geändertes)
        $this->inbox->update($id, ['item_edits' => ['working_prices' => ['0' => ['action' => 'correct', 'value' => ['from' => '2025-01-01']]]]]);
        $pv2 = $this->inbox->preview($id);
        self::assertEqualsWithDelta(12.1, (float)$pv2['entry']['extraction']['fields']['working_prices']['items'][0]['value']['ct'], 0.0001);
        $price = self::byKind($pv2, 'contract_price')[0];
        self::assertEqualsWithDelta(12.1, (float)$price['data']['value'], 0.0001, 'der Vorschlag nimmt den korrigierten Wert');
        self::assertSame([false, true], [$price['check'], $price['checked']]);
        // übernehmen schreibt den korrigierten Preis an den Vertrag
        $this->inbox->apply($id, [$price['id']]);
        $rows = array_column($this->contracts->list('gas')[0]['working_prices'], 'ct_per_kwh', 'from');
        self::assertEqualsWithDelta(12.1, (float)$rows['2025-01-01'], 0.0001);
    }

    public function testADiscardedLineMakesNoProposalAndCanBeRestored(): void
    {
        $pv = $this->invoice();
        $id = (string)$pv['entry']['id'];
        self::assertCount(1, self::byKind($pv, 'gas_factor'));
        $this->inbox->update($id, ['item_edits' => ['gas_factors' => ['0' => ['action' => 'discard']]]]);
        $pv2 = $this->inbox->preview($id);
        self::assertSame([], self::byKind($pv2, 'gas_factor'), 'verworfen: kein Vorschlag');
        $f = $pv2['entry']['extraction']['fields']['gas_factors'];
        self::assertSame(['discarded', 'discard'], [$f['items'][0]['status'], $f['items'][0]['decision']], 'in der Anzeige an seiner Stelle');
        self::assertSame('empty', $f['status']);
        // zurück auf „wie gelesen“
        $this->inbox->update($id, ['item_edits' => ['gas_factors' => ['0' => null]]]);
        $pv3 = $this->inbox->preview($id);
        self::assertCount(1, self::byKind($pv3, 'gas_factor'));
        self::assertArrayNotHasKey('item_edits', array_values(array_filter($this->inbox->rawEntries(), fn($e) => $e['id'] === $id))[0]);
    }

    /** Verworfene Abschläge zählen nicht zur Summe, die die Rechnung gegen die Abschläge prüft. */
    public function testTheSumOfAdvancesFollowsTheLines(): void
    {
        $pv = $this->invoice();
        $id = (string)$pv['entry']['id'];
        $this->inbox->update($id, ['item_edits' => ['advances_paid' => ['1' => ['action' => 'discard']]]]);
        $e = array_values(array_filter($this->inbox->rawEntries(), fn($x) => $x['id'] === $id))[0];
        self::assertEqualsWithDelta(1680.0, (float)$e['extraction']['fields']['advances_paid']['sum'], 0.001, 'gespeichert wie gelesen');
        self::assertEqualsWithDelta(840.0, (float)ItemEdits::apply($e)['extraction']['fields']['advances_paid']['sum'], 0.001);
    }

    public function testWrongDecisionsAreRefusedWithACode(): void
    {
        $pv = $this->invoice();
        $id = (string)$pv['entry']['id'];
        $cases = [
            'errors.inbox.itemEditInvalid' => [
                ['nope' => ['0' => ['action' => 'confirm']]],               // kein Feld der Art
                ['supplier' => ['0' => ['action' => 'confirm']]],           // kein Listenfeld
                ['working_prices' => ['7' => ['action' => 'confirm']]],     // keine solche Stelle
                ['working_prices' => ['0' => ['action' => 'delete']]],      // keine solche Entscheidung
                ['working_prices' => ['0' => ['action' => 'correct']]],     // ohne Wert
                ['working_prices' => ['0' => ['action' => 'correct', 'value' => ['euro' => 1]]]],   // kein Unterfeld
                'confirm',
            ],
            'errors.inbox.editInvalid' => [
                ['working_prices' => ['0' => ['action' => 'correct', 'value' => ['ct' => 'viel']]]],
                ['working_prices' => ['0' => ['action' => 'correct', 'value' => ['from' => '31.02.2025']]]],
                ['working_prices' => ['0' => ['action' => 'correct', 'value' => ['basis' => 'irgendwie']]]],
            ],
        ];
        foreach ($cases as $code => $list) {
            foreach ($list as $patch) {
                try {
                    $this->inbox->update($id, ['item_edits' => $patch]);
                    self::fail('angenommen: ' . json_encode($patch));
                } catch (LocalizedException $e) {
                    self::assertSame($code, $e->key, json_encode($patch) ?: '');
                }
            }
        }
        self::assertArrayNotHasKey('item_edits', array_values(array_filter($this->inbox->rawEntries(), fn($e) => $e['id'] === $id))[0]);
    }

    public function testReadingAgainOrAnotherTypeClearsTheDecisions(): void
    {
        $pv = $this->invoice();
        $id = (string)$pv['entry']['id'];
        $this->inbox->update($id, ['item_edits' => ['working_prices' => ['0' => ['action' => 'confirm']]]]);
        $this->inbox->extract($id, InboxFixture::invoicePages(), false, true);
        $raw = array_values(array_filter($this->inbox->rawEntries(), fn($e) => $e['id'] === $id))[0];
        self::assertArrayNotHasKey('item_edits', $raw, 'neue Auslesung, neue Stellen');
    }

    /** Der Haushalt aus Dokumenten folgt denselben Entscheidungen (InboxDraftService::entries). */
    public function testTheHouseholdDraftHonoursTheDecisions(): void
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings);
        $draft = new \Energietracker\Services\InboxDraftService($this->inbox,
            new \Energietracker\Services\Inbox\HouseholdDraft($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n, $proposals),
            new \Energietracker\Services\Inbox\DraftApplier($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n));
        $pv = $this->invoice();
        $kinds = fn(): array => array_column((array)($draft->draft(null, '2026-03-01')['items'] ?? []), 'kind');
        self::assertContains('gas_factor', $kinds());
        $this->inbox->update((string)$pv['entry']['id'], ['item_edits' => ['gas_factors' => ['0' => ['action' => 'discard']]]]);
        self::assertNotContains('gas_factor', $kinds(), 'verworfen: auch im Entwurf nicht');
    }

    /** v3.13.0 (Paket F, Längengrenzen) — Notiz und korrigierter Text am Dokument: Fehler mit Grenze statt still gekürzt. */
    public function testNoteAndCorrectedTextHaveALimit(): void
    {
        $pv = $this->invoice();
        $id = (string)$pv['entry']['id'];
        $this->inbox->update($id, ['note' => str_repeat('n', 500)]);
        foreach ([['note' => str_repeat('n', 501)], ['edits' => ['supplier' => str_repeat('s', 201)]],
                  ['item_edits' => ['credits' => []], 'note' => str_repeat('n', 501)]] as $patch) {
            try {
                $this->inbox->update($id, $patch);
                self::fail('zu lang angenommen');
            } catch (LocalizedException $e) {
                self::assertSame('errors.common.tooLong', $e->key);
            }
        }
        $raw = array_values(array_filter($this->inbox->rawEntries(), fn($e) => $e['id'] === $id))[0];
        self::assertSame(500, mb_strlen((string)$raw['note']));
        self::assertArrayNotHasKey('supplier', (array)$raw['edits']);
    }

    public function testDecisionsTravelWithTheBackup(): void
    {
        $pv = $this->invoice();
        $id = (string)$pv['entry']['id'];
        $this->inbox->update($id, ['item_edits' => ['working_prices' => ['0' => ['action' => 'correct', 'value' => ['ct' => 12.1]]]]]);
        $backup = new BackupService($this->store, $this->i18n);
        $dump = json_decode((string)json_encode($backup->export(false)), true);
        $this->store->write('inbox.json', []);
        $backup->import($dump, false, true);
        $raw = array_values(array_filter($this->inbox->rawEntries(), fn($e) => $e['id'] === $id))[0];
        self::assertSame(['correct', ['ct' => 12.1]], [$raw['item_edits']['working_prices']['0']['action'], $raw['item_edits']['working_prices']['0']['value']]);
    }
}
