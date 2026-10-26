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
 * v3.9.0 (F1034) — der neue Abschlag einer Rechnung mit Rhythmus auch im
 * Entwurf des Haushalts: Betrag je Termin, alle zwei Monate, mit den Terminen
 * der Rechnung — wie im Posteingang (InvoicePrices::advancePlan), nie still als
 * Monatsbetrag. Ohne Rhythmus bleibt die Zeile wie bisher. Erfundene Werte.
 */
#[CoversClass(HouseholdDraft::class)]
#[CoversClass(DraftApplier::class)]
final class HouseholdDraftAdvanceRhythmTest extends ServiceTestCase
{
    private const TODAY = '2026-01-15';

    private InboxService $inbox;
    private InboxDraftService $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $proposals = new Proposals($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings);
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, InboxFixture::provider()),
            $proposals, new Applier($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n));
        $this->draft = new InboxDraftService($this->inbox,
            new HouseholdDraft($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n, $proposals),
            new DraftApplier($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n));
        $this->draft->attach(new BackupService($this->store, $this->i18n), new ActivityService($this->store, $this->settings, $this->i18n));
    }

    private function invoice(array $more): void
    {
        DraftFixture::add($this->store, fn($b, $n) => $this->inbox->upload($b, $n), 'i1', 'invoice', $more + [
            'utility' => 'strom', 'supplier' => 'Nordlicht Energie GmbH', 'kind' => 'annual',
            'period_from' => '01.01.2025', 'period_to' => '31.12.2025', 'consumption' => '1800',
            'reading_old' => '1000', 'reading_old_date' => '01.01.2025', 'reading_new' => '2800', 'reading_new_date' => '31.12.2025',
            'total_amount' => '640,00', 'advances_total' => '600,00', 'result_kind' => 'due', 'result_amount' => '40,00',
            'meter_number' => 'STR-1001', 'contract_number' => 'NL-4401',
        ]);
    }

    private function advances(array $d): array
    {
        $a = array_values(array_filter($d['items'], fn($i) => $i['kind'] === 'advances' && $i['utility'] === 'strom'));
        self::assertCount(1, $a, json_encode(array_column($d['items'], 'kind')));
        return $a[0];
    }

    public function testTheRhythmOfTheBillGoesIntoTheContract(): void
    {
        $this->invoice(['advance_new' => '110,00',
            'advance_new_dates' => [['date' => '15.03.2026'], ['date' => '15.05.2026'], ['date' => '15.07.2026']]]);
        $d = $this->draft->draft(null, self::TODAY);
        $f = $this->advances($d)['fields'][0];
        // ohne „ab“ beginnt der Plan am ersten Termin (nicht am angenommenen 01.02.)
        self::assertSame(['ap:2026-03-15', 110.0, ['every_months' => 2, 'due_dates' => ['2026-03-15', '2026-05-15', '2026-07-15']]],
            [$f['name'], (float)$f['value'], $f['row'] ?? null]);
        self::assertNotContains('assumed_date', $f['reasons']);
        $ids = array_column(array_filter($d['items'], fn($i) => $i['checked']), 'id');
        $res = $this->draft->apply($ids, [], [], null, self::TODAY);
        self::assertSame([], $res['failed'] ?? [], json_encode($res['failed'] ?? []));
        $c = $this->contracts->list('strom')[0];
        // assertEquals: der Speicher gibt 110.0 als 110 zurück
        self::assertEquals([['from' => '2026-03-15', 'amount_eur' => 110.0, 'every_months' => 2, 'due_dates' => ['2026-03-15', '2026-05-15', '2026-07-15']]],
            $c['advance_payments'], 'je Termin, alle zwei Monate');
    }

    /** „monatlich“ laut Feld, die Termine aber alle zwei Monate (eins am Wochenende verschoben): die Termine gelten, zu prüfen. */
    public function testARhythmThatContradictsTheDatesIsTakenFromTheDatesAndChecked(): void
    {
        $this->invoice(['advance_new' => '110,00', 'advance_new_every' => 'monthly',
            'advance_new_dates' => [['date' => '16.10.2026'], ['date' => '16.12.2026'], ['date' => '15.02.2027']]]);
        $f = $this->advances($this->draft->draft(null, self::TODAY))['fields'][0];
        self::assertSame([2, true], [$f['row']['every_months'] ?? null, $f['check']]);
        self::assertContains('rhythm_conflict', $f['reasons']);
        // ohne Feld: aus den Terminen, vorbelegt
        $this->store->write('inbox.json', []);
        $this->invoice(['advance_new' => '110,00', 'advance_new_dates' => [['date' => '16.10.2026'], ['date' => '16.12.2026'], ['date' => '15.02.2027']]]);
        $g = $this->advances($this->draft->draft(null, self::TODAY))['fields'][0];
        self::assertSame([2, false], [$g['row']['every_months'] ?? null, $g['check']]);
    }

    public function testWithoutRhythmTheRowStaysMonthly(): void
    {
        $this->invoice(['advance_new' => '55,00', 'advance_new_from' => '01.02.2026']);
        $f = $this->advances($this->draft->draft(null, self::TODAY))['fields'][0];
        self::assertSame(['ap:2026-02-01', 55.0], [$f['name'], (float)$f['value']]);
        self::assertArrayNotHasKey('row', $f, 'monatlich: nichts Neues am Feld');
    }
}
